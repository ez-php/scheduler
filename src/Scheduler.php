<?php

declare(strict_types=1);

namespace EzPhp\Scheduler;

use DateTimeInterface;

/**
 * Class Scheduler
 *
 * Registry of scheduled commands. Service providers call command() to register
 * entries; the application's schedule:run command calls run() each minute.
 *
 * Usage (in a ServiceProvider boot()):
 *
 *   $scheduler = $app->make(Scheduler::class);
 *   $scheduler->command('queue:work')->everyFiveMinutes()->withoutOverlapping();
 *   $scheduler->command('cache:prune')->daily();
 *
 * Calling run() with a callable executor invokes the callable once per due
 * entry. When a MutexInterface is configured and an entry declares
 * withoutOverlapping(), the entry is skipped silently if the lock is taken,
 * and the lock is released (via finally) after the executor returns.
 *
 * @package EzPhp\Scheduler
 */
final class Scheduler
{
    /**
     * @var list<ScheduleEntry>
     */
    private array $entries = [];

    /**
     * @param MutexInterface|null $mutex Optional mutex for withoutOverlapping() support.
     */
    public function __construct(private readonly ?MutexInterface $mutex = null)
    {
    }

    /**
     * Register a command and return its ScheduleEntry for frequency configuration.
     *
     * @param string $name Console command name (e.g. 'queue:work').
     *
     * @return ScheduleEntry
     */
    public function command(string $name): ScheduleEntry
    {
        $entry = new ScheduleEntry($name);
        $this->entries[] = $entry;

        return $entry;
    }

    /**
     * Return all entries that are due at the given time.
     *
     * @param DateTimeInterface $time The moment to check against (typically now).
     *
     * @return list<ScheduleEntry>
     */
    public function dueEntries(DateTimeInterface $time): array
    {
        return array_values(array_filter(
            $this->entries,
            static fn (ScheduleEntry $e): bool => $e->isDue($time),
        ));
    }

    /**
     * Return all registered entries regardless of due status.
     *
     * @return list<ScheduleEntry>
     */
    public function all(): array
    {
        return $this->entries;
    }

    /**
     * Run all due entries, respecting mutex locks for entries declared with
     * withoutOverlapping().
     *
     * @param DateTimeInterface    $time     The moment to evaluate (typically now).
     * @param callable(string): void $executor Called with the command name for each
     *                                         due entry that is not skipped. In an
     *                                         ez-php application this would call
     *                                         Console::call($commandName).
     *
     * @throws SchedulerException When withoutOverlapping() is used but no
     *                            MutexInterface was configured.
     *
     * @return void
     */
    public function run(DateTimeInterface $time, callable $executor): void
    {
        foreach ($this->dueEntries($time) as $entry) {
            if ($entry->shouldSkipIfOverlapping()) {
                if ($this->mutex === null) {
                    throw new SchedulerException(
                        "Cannot use withoutOverlapping() for command '{$entry->getCommand()}': "
                        . 'no MutexInterface configured on the Scheduler.'
                    );
                }

                $key = $entry->getMutexKey();

                if (!$this->mutex->acquire($key)) {
                    continue; // already running — skip silently
                }

                try {
                    $executor($entry->getCommand());
                } finally {
                    $this->mutex->release($key);
                }
            } else {
                $executor($entry->getCommand());
            }
        }
    }
}
