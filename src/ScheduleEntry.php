<?php

declare(strict_types=1);

namespace EzPhp\Scheduler;

use DateTimeInterface;

/**
 * Class ScheduleEntry
 *
 * Represents a single registered command with its frequency predicate and
 * optional overlap-prevention flag. Frequency methods return $this for
 * fluent chaining.
 *
 * Usage in a service provider boot():
 *
 *   $scheduler->command('queue:work')
 *       ->everyFiveMinutes()
 *       ->withoutOverlapping();
 *
 * @package EzPhp\Scheduler
 */
final class ScheduleEntry
{
    /**
     * @var \Closure(DateTimeInterface): bool
     */
    private \Closure $duePredicate;

    private string $frequencyDescription;

    private bool $noOverlap = false;

    private ?string $jobName = null;

    /**
     * @param string $command Console command name as registered in the application.
     */
    public function __construct(private readonly string $command)
    {
        $this->duePredicate = static fn (DateTimeInterface $t): bool => false;
        $this->frequencyDescription = 'not scheduled';
    }

    // ── Frequency API ─────────────────────────────────────────────────────────

    /**
     * Schedule the command to run every minute.
     *
     * @return $this
     */
    public function everyMinute(): self
    {
        $this->duePredicate = static fn (DateTimeInterface $t): bool => true;
        $this->frequencyDescription = 'every minute';

        return $this;
    }

    /**
     * Schedule the command to run every five minutes (at :00, :05, :10, … :55).
     *
     * @return $this
     */
    public function everyFiveMinutes(): self
    {
        $this->duePredicate = static fn (DateTimeInterface $t): bool => (int) $t->format('i') % 5 === 0;
        $this->frequencyDescription = 'every five minutes';

        return $this;
    }

    /**
     * Schedule the command to run once per hour (at minute :00).
     *
     * @return $this
     */
    public function hourly(): self
    {
        $this->duePredicate = static fn (DateTimeInterface $t): bool => (int) $t->format('i') === 0;
        $this->frequencyDescription = 'every hour';

        return $this;
    }

    /**
     * Schedule the command to run once per day at midnight (00:00).
     *
     * @return $this
     */
    public function daily(): self
    {
        $this->duePredicate = static fn (DateTimeInterface $t): bool => $t->format('Hi') === '0000';
        $this->frequencyDescription = 'daily at midnight';

        return $this;
    }

    /**
     * Schedule the command to run once per week on Sunday at midnight.
     *
     * @return $this
     */
    public function weekly(): self
    {
        // w=0 (Sunday), Hi=0000
        $this->duePredicate = static fn (DateTimeInterface $t): bool => $t->format('wHi') === '00000';
        $this->frequencyDescription = 'weekly on Sunday at midnight';

        return $this;
    }

    /**
     * Schedule the command to run once per month on the 1st at midnight.
     *
     * @return $this
     */
    public function monthly(): self
    {
        // d=01, Hi=0000
        $this->duePredicate = static fn (DateTimeInterface $t): bool => $t->format('dHi') === '010000';
        $this->frequencyDescription = 'monthly on the 1st at midnight';

        return $this;
    }

    // ── Name ─────────────────────────────────────────────────────────────────

    /**
     * Assign a human-readable name to this scheduled job.
     * The name is used in log messages and as the mutex key when set.
     *
     * @param string $name A short, unique identifier for this job (e.g. 'user-tick').
     *
     * @return $this
     */
    public function name(string $name): self
    {
        $this->jobName = $name;

        return $this;
    }

    /**
     * Return the job name, or null if none was set.
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->jobName;
    }

    // ── Overlap prevention ────────────────────────────────────────────────────

    /**
     * Prevent concurrent execution of this command via a mutex lock.
     *
     * When enabled the Scheduler will skip this entry if the lock is already
     * held (i.e. a previous invocation is still running). Requires a
     * MutexInterface to be configured on the Scheduler.
     *
     * @param bool $enabled Pass false to disable (useful for subclass overrides).
     *
     * @return $this
     */
    public function withoutOverlapping(bool $enabled = true): self
    {
        $this->noOverlap = $enabled;

        return $this;
    }

    // ── Getters ───────────────────────────────────────────────────────────────

    /**
     * Return the command name.
     *
     * @return string
     */
    public function getCommand(): string
    {
        return $this->command;
    }

    /**
     * Return a human-readable description of the configured frequency.
     *
     * @return string
     */
    public function getFrequencyDescription(): string
    {
        return $this->frequencyDescription;
    }

    /**
     * Return whether overlap prevention is active for this entry.
     *
     * @internal Called by Scheduler::run(); not part of the public entry API.
     *
     * @return bool
     */
    public function shouldSkipIfOverlapping(): bool
    {
        return $this->noOverlap;
    }

    /**
     * Return a stable mutex key derived from the command name.
     *
     * @internal Called by Scheduler::run(); not part of the public entry API.
     *
     * @return string
     */
    public function getMutexKey(): string
    {
        return 'scheduler:' . sha1($this->jobName ?? $this->command);
    }

    /**
     * Check whether this entry is due at the given time.
     *
     * @internal Called by Scheduler::dueEntries(); not part of the public entry API.
     *
     * @param DateTimeInterface $time The moment to evaluate (typically now).
     *
     * @return bool
     */
    public function isDue(DateTimeInterface $time): bool
    {
        return ($this->duePredicate)($time);
    }
}
