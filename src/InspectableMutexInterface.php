<?php

declare(strict_types=1);

namespace EzPhp\Scheduler;

/**
 * Interface InspectableMutexInterface
 *
 * Optional extension of MutexInterface for mutexes that can report whether a
 * key is currently held without trying to take it. Used by `scheduler:list`.
 *
 * A probe that acquires and releases would be visible to a concurrent
 * `scheduler:run` — its own acquire() could fail in that window and skip the
 * entry — so only mutexes with a genuinely read-only check implement this
 * (RedisMutex, DatabaseMutex, DatabaseMutexWithExpiry). FileMutex (flock()) and
 * CacheMutex (LockInterface) have no such check and report no state.
 *
 * @package EzPhp\Scheduler
 */
interface InspectableMutexInterface extends MutexInterface
{
    /**
     * Whether the key is currently held by any process. Never acquires or releases.
     *
     * @param string $key Mutex key (see ScheduleEntry::getMutexKey()).
     *
     * @return bool
     */
    public function isLocked(string $key): bool;
}
