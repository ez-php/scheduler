<?php

declare(strict_types=1);

namespace EzPhp\Scheduler\Mutex;

use EzPhp\Scheduler\MutexInterface;
use PDO;
use PDOException;

/**
 * Class DatabaseMutexWithExpiry
 *
 * PDO-based mutex that stores locks with an expiry timestamp in a
 * `scheduler_locks_ttl` table. Unlike {@see DatabaseMutex}, a lock left behind
 * by a crashed process does not block the schedule forever: the next acquire
 * reclaims any lock whose `expires_at` has passed.
 *
 * The TTL is a safety net, not a run-duration limit. Choose a value comfortably
 * longer than the worst-case runtime of the scheduled command — a TTL shorter
 * than the actual runtime lets a second process reclaim the lock while the
 * first is still working, which defeats overlap prevention.
 *
 * Release is owner-only: acquire() stores a random token in the row's `owner`
 * column and remembers it per key, and release() deletes the row only while it
 * still carries that token. A run that overruns its TTL therefore cannot delete
 * the lock another process has reclaimed since, and releasing a key this
 * instance never acquired is a no-op — the same contract as RedisMutex.
 *
 * The `scheduler_locks_ttl` table is created automatically on construction —
 * no migration is required. A table created before owner tokens existed gets
 * its `owner` column added in place.
 *
 * Compatible with MySQL and SQLite.
 *
 * @package EzPhp\Scheduler\Mutex
 */
final class DatabaseMutexWithExpiry implements MutexInterface
{
    /**
     * Default lock lifetime in seconds (one hour).
     */
    public const int DEFAULT_TTL_SECONDS = 3600;

    /**
     * A separate table from `DatabaseMutex`'s `scheduler_locks` — deliberately.
     *
     * Both classes create their table with `CREATE TABLE IF NOT EXISTS`, which is
     * a no-op when the table already exists and therefore never adds a column to
     * an existing one. Reusing `scheduler_locks` here would mean querying an
     * `expires_at` column that does not exist on any already-deployed install,
     * raising a PDOException at runtime.
     */
    private const string TABLE = 'scheduler_locks_ttl';

    /**
     * Owner token per key this instance currently holds.
     *
     * @var array<string, string>
     */
    private array $tokens = [];

    /**
     * @param PDO $pdo         Database connection. The calling code is responsible for
     *                         configuring PDO::ERRMODE_EXCEPTION.
     * @param int $ttlSeconds  Lock lifetime in seconds. Values <= 0 make every lock
     *                         immediately reclaimable (used in tests).
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' ('
            . 'lock_key VARCHAR(255) NOT NULL,'
            . 'expires_at INTEGER NOT NULL,'
            . "owner VARCHAR(64) NOT NULL DEFAULT '',"
            . 'PRIMARY KEY (lock_key)'
            . ')'
        );

        $this->addOwnerColumnIfMissing();
    }

    /**
     * Try to acquire the lock, reclaiming it first if the previous holder's
     * lock has expired.
     *
     * Reclaim and insert are two statements rather than one. They do not need to
     * be atomic together: the primary key on `lock_key` is what guarantees
     * mutual exclusion. If two processes both delete the same expired row, only
     * one of their inserts can succeed — the other hits a duplicate key and
     * returns false, which is the correct outcome.
     *
     * @param string $key Unique lock identifier.
     *
     * @return bool True when the lock was acquired; false when held by someone else.
     */
    public function acquire(string $key): bool
    {
        $now = time();

        // Reclaim this key if the previous holder's lock has expired.
        $stmt = $this->pdo->prepare(
            'DELETE FROM ' . self::TABLE . ' WHERE lock_key = ? AND expires_at <= ?'
        );
        $stmt->execute([$key, $now]);

        $token = bin2hex(random_bytes(16));

        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO ' . self::TABLE . ' (lock_key, expires_at, owner) VALUES (?, ?, ?)'
            );
            $stmt->execute([$key, $now + $this->ttlSeconds, $token]);

            if ($stmt->rowCount() < 1) {
                return false;
            }

            $this->tokens[$key] = $token;

            return true;
        } catch (PDOException) {
            return false; // duplicate key — lock still held and not yet expired
        }
    }

    /**
     * Release the lock — only while the row still carries this instance's token.
     *
     * @param string $key The same key passed to acquire().
     *
     * @return void
     */
    public function release(string $key): void
    {
        $token = $this->tokens[$key] ?? null;

        if ($token === null) {
            return;
        }

        unset($this->tokens[$key]);

        $stmt = $this->pdo->prepare('DELETE FROM ' . self::TABLE . ' WHERE lock_key = ? AND owner = ?');
        $stmt->execute([$key, $token]);
    }

    /**
     * Upgrade a table created before owner tokens existed.
     *
     * `CREATE TABLE IF NOT EXISTS` never alters an existing table, so probe the
     * column and add it when the probe fails. Rows written by an older version
     * get the empty owner and simply expire; no live lock carries it afterwards.
     *
     * @return void
     */
    private function addOwnerColumnIfMissing(): void
    {
        try {
            $hasColumn = $this->pdo->query('SELECT owner FROM ' . self::TABLE . ' WHERE 1 = 0') !== false;
        } catch (PDOException) {
            $hasColumn = false;
        }

        if (!$hasColumn) {
            $this->pdo->exec('ALTER TABLE ' . self::TABLE . " ADD COLUMN owner VARCHAR(64) NOT NULL DEFAULT ''");
        }
    }
}
