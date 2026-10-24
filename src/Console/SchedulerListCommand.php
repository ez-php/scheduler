<?php

declare(strict_types=1);

namespace EzPhp\Scheduler\Console;

use DateTimeImmutable;
use EzPhp\Console\CommandInterface;
use EzPhp\Console\Output;
use EzPhp\Scheduler\Scheduler;

/**
 * Class SchedulerListCommand
 *
 * `scheduler:list` — prints every registered ez-php/scheduler entry with its
 * frequency, overlap setting, the next due minute (searched up to 31 days ahead)
 * and the current lock state.
 *
 * The lock column reads the mutex without acquiring it, so listing never makes a
 * concurrent `scheduler:run` skip an entry; mutexes without such a read-only check
 * (FileMutex, CacheMutex) show `unknown`. Named `scheduler:list`, not
 * `schedule:list`, for the same reason as `scheduler:run`.
 *
 * @package EzPhp\Scheduler\Console
 */
final class SchedulerListCommand implements CommandInterface
{
    /**
     * SchedulerListCommand Constructor
     *
     * @param Scheduler $scheduler The application's configured Scheduler.
     */
    public function __construct(private readonly Scheduler $scheduler)
    {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'scheduler:list';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'List ez-php/scheduler entries with frequency, next run and lock state';
    }

    /**
     * @return string
     */
    public function getHelp(): string
    {
        return "Usage: ez scheduler:list\n\n"
            . "Lists every registered ez-php/scheduler entry. \"Next run\" is searched minute\n"
            . "by minute up to 31 days ahead. \"Lock\" is read without acquiring the mutex;\n"
            . 'it shows "-" without withoutOverlapping() and "unknown" for File/Cache mutexes.';
    }

    /**
     * @param list<string> $args Unused.
     *
     * @return int Always 0.
     */
    public function handle(array $args): int
    {
        $entries = $this->scheduler->all();

        if ($entries === []) {
            Output::line('No scheduled commands registered.');

            return 0;
        }

        $now = new DateTimeImmutable();
        $rows = [];

        foreach ($entries as $entry) {
            $overlap = $entry->shouldSkipIfOverlapping();
            $next = $entry->nextRunAfter($now);
            $locked = $this->scheduler->isLocked($entry);

            $rows[] = [
                $entry->getName() ?? $entry->getCommand(),
                $entry->getCommand(),
                $entry->getFrequencyDescription(),
                $overlap ? 'yes' : 'no',
                $next?->format('Y-m-d H:i') ?? 'not within 31 days',
                match (true) {
                    !$overlap => '-',
                    $locked === null => 'unknown',
                    $locked => 'locked',
                    default => 'free',
                },
            ];
        }

        Output::table(['Name', 'Command', 'Frequency', 'No overlap', 'Next run', 'Lock'], $rows);

        return 0;
    }
}
