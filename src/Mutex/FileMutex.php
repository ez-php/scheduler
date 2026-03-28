<?php

declare(strict_types=1);

namespace EzPhp\Scheduler\Mutex;

use EzPhp\Scheduler\MutexInterface;
use EzPhp\Scheduler\SchedulerException;

/**
 * Class FileMutex
 *
 * File-based mutex using PHP's flock(). Each key maps to a dedicated lock
 * file inside the configured directory. Acquiring a lock opens the file and
 * calls flock(LOCK_EX|LOCK_NB) — if the file is already exclusively locked
 * (e.g. by another cron process) the call returns false immediately.
 *
 * Lock files are never deleted so that their inodes remain stable across
 * processes; the lock is advisory and tied to the file handle, not the path.
 *
 * @package EzPhp\Scheduler\Mutex
 */
final class FileMutex implements MutexInterface
{
    /**
     * Open file handles keyed by lock key.
     *
     * @var array<string, resource>
     */
    private array $handles = [];

    /**
     * @param string $lockDir Absolute path to the directory where lock files are stored.
     *                        Created automatically when it does not exist.
     *
     * @throws SchedulerException When the lock directory cannot be created.
     */
    public function __construct(private readonly string $lockDir)
    {
        if (!is_dir($lockDir) && !mkdir($lockDir, 0o755, true) && !is_dir($lockDir)) {
            throw new SchedulerException("Failed to create lock directory: {$lockDir}");
        }
    }

    /**
     * Try to acquire an exclusive non-blocking lock for the given key.
     *
     * @param string $key Unique lock identifier.
     *
     * @return bool True when the lock was acquired; false when already held.
     *
     * @throws SchedulerException When the lock file cannot be opened.
     */
    public function acquire(string $key): bool
    {
        $path = $this->lockDir . '/' . sha1($key) . '.lock';
        $handle = fopen($path, 'c');

        if ($handle === false) {
            throw new SchedulerException("Cannot open lock file: {$path}");
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }

        $this->handles[$key] = $handle;

        return true;
    }

    /**
     * Release the lock for the given key.
     *
     * @param string $key The same key passed to acquire().
     *
     * @return void
     */
    public function release(string $key): void
    {
        if (!isset($this->handles[$key])) {
            return;
        }

        flock($this->handles[$key], LOCK_UN);
        fclose($this->handles[$key]);
        unset($this->handles[$key]);
    }
}
