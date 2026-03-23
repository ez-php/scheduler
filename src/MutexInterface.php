<?php

declare(strict_types=1);

namespace EzPhp\Scheduler;

/**
 * Interface MutexInterface
 *
 * Contract for mutex implementations used by the Scheduler to prevent
 * overlapping execution of the same scheduled command across concurrent
 * cron invocations.
 *
 * Implementations must be safe for concurrent use across independent
 * processes — in-memory implementations are not suitable for production.
 *
 * @package EzPhp\Scheduler
 */
interface MutexInterface
{
    /**
     * Try to acquire an exclusive lock for the given key.
     *
     * Returns true when the lock was successfully acquired.
     * Returns false when the lock is already held (non-blocking).
     *
     * @param string $key Unique lock identifier (e.g. a command name hash).
     *
     * @return bool
     */
    public function acquire(string $key): bool;

    /**
     * Release the lock for the given key.
     *
     * Calling release() on a key that was never acquired is a no-op.
     *
     * @param string $key The same key passed to acquire().
     *
     * @return void
     */
    public function release(string $key): void;
}
