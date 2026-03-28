<?php

declare(strict_types=1);

namespace EzPhp\Scheduler\Mutex;

use EzPhp\Scheduler\MutexInterface;
use PDO;
use PDOException;

/**
 * Class DatabaseMutex
 *
 * PDO-based mutex that stores active locks in a `scheduler_locks` table.
 * Acquiring a lock inserts a row; releasing it deletes the row. A duplicate
 * key error (caught as PDOException) indicates the lock is already held.
 *
 * The `scheduler_locks` table is created automatically on construction —
 * no migration is required.
 *
 * Compatible with MySQL and SQLite. The `acquired_at` column is informational
 * (visible in the table for debugging stale locks).
 *
 * @package EzPhp\Scheduler\Mutex
 */
final class DatabaseMutex implements MutexInterface
{
    /**
     * @param PDO $pdo Database connection. The calling code is responsible for
     *                 configuring PDO::ERRMODE_EXCEPTION.
     */
    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS scheduler_locks ('
            . 'lock_key VARCHAR(255) NOT NULL,'
            . 'acquired_at DATETIME DEFAULT CURRENT_TIMESTAMP,'
            . 'PRIMARY KEY (lock_key)'
            . ')'
        );
    }

    /**
     * Try to acquire the lock by inserting a row.
     *
     * @param string $key Unique lock identifier.
     *
     * @return bool True when inserted (lock acquired); false on duplicate key.
     */
    public function acquire(string $key): bool
    {
        try {
            $stmt = $this->pdo->prepare('INSERT INTO scheduler_locks (lock_key) VALUES (?)');
            $stmt->execute([$key]);

            return $stmt->rowCount() > 0;
        } catch (PDOException) {
            return false; // duplicate key — lock already held
        }
    }

    /**
     * Release the lock by deleting the row.
     *
     * @param string $key The same key passed to acquire().
     *
     * @return void
     */
    public function release(string $key): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM scheduler_locks WHERE lock_key = ?');
        $stmt->execute([$key]);
    }
}
