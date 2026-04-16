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
 * An optional logger closure receives plain-text messages for each START,
 * DONE, and ERROR event. Pass a PSR-3 logger's info/error methods wrapped
 * in a closure, or any callable(string): void.
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
     * @param MutexInterface|null                $mutex  Optional mutex for withoutOverlapping() support.
     * @param (\Closure(string): void)|null      $logger Optional log sink. Called with a plain-text
     *                                                   message on START, DONE, and ERROR events.
     */
    public function __construct(
        private readonly ?MutexInterface $mutex = null,
        private readonly ?\Closure $logger = null,
    ) {
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
                    $this->executeEntry($entry, $executor);
                } finally {
                    $this->mutex->release($key);
                }
            } else {
                $this->executeEntry($entry, $executor);
            }
        }
    }

    /**
     * Execute a single entry and log start, finish, and any error.
     *
     * @param ScheduleEntry        $entry
     * @param callable(string): void $executor
     *
     * @return void
     */
    private function executeEntry(ScheduleEntry $entry, callable $executor): void
    {
        $label = $entry->getName() ?? $entry->getCommand();
        $start = microtime(true);

        $this->log('[START] ' . $label . ' at ' . date('Y-m-d H:i:s'));

        try {
            $executor($entry->getCommand());
            $elapsed = round(microtime(true) - $start, 3);
            $this->log('[DONE]  ' . $label . ' finished in ' . $elapsed . 's');
        } catch (\Throwable $e) {
            $elapsed = round(microtime(true) - $start, 3);
            $this->log('[ERROR] ' . $label . ' failed after ' . $elapsed . 's: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * @param string $message
     *
     * @return void
     */
    private function log(string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($message);
        }
    }
}
