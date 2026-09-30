<?php

declare(strict_types=1);

namespace EzPhp\Scheduler\Mutex;

use EzPhp\Scheduler\MutexInterface;
use Redis;
use RedisException;
use RuntimeException;

/**
 * Class RedisMutex
 *
 * Redis-backed mutex using `SET key value NX EX ttl`. `NX` makes the write
 * succeed only when the key is absent, so the set-if-not-exists test and the
 * write are a single atomic Redis command — no read-then-write race between
 * concurrent cron processes.
 *
 * This is the driver to use when scheduled commands run on more than one host:
 * {@see FileMutex} is limited to one filesystem and {@see DatabaseMutex} to one
 * database, while Redis is typically already shared across application servers.
 *
 * Every lock carries a TTL, so a process that dies without releasing does not
 * block the schedule forever — Redis expires the key on its own.
 *
 * The stored value is a random owner token, remembered per key by this
 * instance. release() deletes the key only while it still holds that token
 * (compare-and-delete in one Lua script): a run that overruns its TTL can't
 * delete the lock another process has taken since, and releasing a key this
 * instance never acquired is a no-op.
 *
 * Requires the PHP `ext-redis` extension.
 *
 * @package EzPhp\Scheduler\Mutex
 */
final class RedisMutex implements MutexInterface
{
    /**
     * Default lock lifetime in seconds (one hour).
     */
    public const int DEFAULT_TTL_SECONDS = 3600;

    /**
     * Namespace for lock keys, so they cannot collide with application data
     * in a shared Redis database.
     */
    public const string KEY_PREFIX = 'ez-php:scheduler:lock:';

    private const string RELEASE_SCRIPT = <<<'LUA'
        if redis.call('GET', KEYS[1]) == ARGV[1] then
            return redis.call('DEL', KEYS[1])
        end
        return 0
        LUA;

    /**
     * Owner tokens of the locks this instance acquired and has not released, by key.
     *
     * @var array<string, string>
     */
    private array $tokens = [];

    /**
     * RedisMutex Constructor
     *
     * @param Redis $redis      Connected Redis client.
     * @param int   $ttlSeconds Lock lifetime. Pick a value comfortably longer than
     *                          the worst-case runtime of the scheduled command — a
     *                          TTL shorter than the runtime lets another process
     *                          reclaim the lock mid-run, defeating overlap prevention.
     *
     * @throws RuntimeException When ext-redis is not loaded.
     */
    public function __construct(
        private readonly Redis $redis,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
        if (!extension_loaded('redis')) {
            throw new RuntimeException('The ext-redis extension is required to use RedisMutex.');
        }
    }

    /**
     * Try to acquire the lock via an atomic `SET … NX EX`.
     *
     * Fails **closed** on a Redis outage — unlike `ez-php/rate-limiter`'s
     * RedisDriver, which fails open. The failure modes are not symmetric: an
     * un-throttled request is a minor problem, whereas running a scheduled job
     * twice concurrently is exactly what this class exists to prevent. When
     * Redis is unreachable the lock cannot be proven free, so the run is skipped.
     *
     * @param string $key Unique lock identifier.
     *
     * @return bool True when the lock was acquired; false when held or unreachable.
     */
    public function acquire(string $key): bool
    {
        $token = bin2hex(random_bytes(16));

        try {
            $acquired = $this->redis->set(
                self::KEY_PREFIX . $key,
                $token,
                ['NX', 'EX' => $this->ttlSeconds],
            ) !== false;
        } catch (RedisException) {
            return false;
        }

        if ($acquired) {
            $this->tokens[$key] = $token;
        }

        return $acquired;
    }

    /**
     * Release the lock if it still carries this instance's token.
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

        try {
            $this->redis->eval(self::RELEASE_SCRIPT, [self::KEY_PREFIX . $key, $token], 1);
        } catch (RedisException) {
            // The lock's TTL will expire it; a failed release is not fatal.
        }
    }
}
