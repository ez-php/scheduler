<?php

declare(strict_types=1);

namespace EzPhp\Scheduler\Mutex;

use EzPhp\Cache\CacheInterface;
use EzPhp\Cache\LockInterface;
use EzPhp\Scheduler\MutexInterface;

/**
 * Class CacheMutex
 *
 * MutexInterface on `ez-php/cache` locks: overlap prevention through whatever
 * cache store the application already runs — RedisDriver, MemcachedDriver,
 * DatabaseDriver (all owner-token locks shared between hosts) or FileDriver
 * (flock(), one host). Every lock carries a TTL, so a process that dies
 * without releasing does not block the schedule forever.
 *
 * release() goes through the same LockInterface object acquire() returned, so
 * it is ownership-aware wherever the cache lock is: a run that overran its TTL
 * can't delete the lock another process has taken since, and releasing a key
 * this instance never acquired is a no-op. Do not use ArrayDriver outside
 * tests — its locks are per process.
 *
 * `ez-php/cache` is a soft dependency (`require-dev` + `suggest`): this class
 * is only autoloaded when referenced, like ez-php/queue's CacheJobLock.
 *
 * @package EzPhp\Scheduler\Mutex
 */
final class CacheMutex implements MutexInterface
{
    /**
     * Default lock lifetime in seconds (one hour).
     */
    public const int DEFAULT_TTL_SECONDS = 3600;

    /**
     * Namespace for lock keys, so they cannot collide with application locks.
     */
    public const string KEY_PREFIX = 'ez-php:scheduler:lock:';

    /**
     * Locks acquired through this instance and not yet released, by key.
     *
     * @var array<string, LockInterface>
     */
    private array $held = [];

    /**
     * CacheMutex Constructor
     *
     * @param CacheInterface $cache      Cache whose lock() backs the mutex.
     * @param int            $ttlSeconds Lock lifetime; pick one comfortably longer than the
     *                                   worst-case runtime of the scheduled command.
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly int $ttlSeconds = self::DEFAULT_TTL_SECONDS,
    ) {
    }

    /**
     * Try to acquire the cache lock for the key (non-blocking).
     *
     * @param string $key Unique lock identifier.
     *
     * @return bool
     */
    public function acquire(string $key): bool
    {
        $lock = $this->cache->lock(self::KEY_PREFIX . $key, $this->ttlSeconds);

        if (!$lock->acquire()) {
            return false;
        }

        $this->held[$key] = $lock;

        return true;
    }

    /**
     * Release the lock acquired for the key; a no-op for any other key.
     *
     * @param string $key
     *
     * @return void
     */
    public function release(string $key): void
    {
        if (!isset($this->held[$key])) {
            return;
        }

        $this->held[$key]->release();
        unset($this->held[$key]);
    }
}
