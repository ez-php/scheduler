<?php

declare(strict_types=1);

namespace Tests\Console;

use EzPhp\Scheduler\Console\SchedulerListCommand;
use EzPhp\Scheduler\Mutex\DatabaseMutex;
use EzPhp\Scheduler\Mutex\FileMutex;
use EzPhp\Scheduler\ScheduleEntry;
use EzPhp\Scheduler\Scheduler;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

#[CoversClass(SchedulerListCommand::class)]
#[UsesClass(Scheduler::class)]
#[UsesClass(ScheduleEntry::class)]
#[UsesClass(DatabaseMutex::class)]
#[UsesClass(FileMutex::class)]
final class SchedulerListCommandTest extends TestCase
{
    private function listOutput(Scheduler $scheduler): string
    {
        $command = new SchedulerListCommand($scheduler);

        ob_start();
        $exit = $command->handle([]);
        $output = (string) ob_get_clean();

        self::assertSame(0, $exit);

        return $output;
    }

    public function testNoEntries(): void
    {
        self::assertStringContainsString('No scheduled commands registered.', $this->listOutput(new Scheduler()));
    }

    public function testListsEntriesWithFrequencyNextRunAndLockState(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $mutex = new DatabaseMutex($pdo);
        $scheduler = new Scheduler($mutex);

        $scheduler->command('reports:send')->daily()->name('nightly-reports');
        $locked = $scheduler->command('queue:schedule')->everyMinute()->withoutOverlapping();
        $scheduler->command('sync:external')->hourly()->withoutOverlapping();
        $scheduler->command('never:runs');
        $mutex->acquire($locked->getMutexKey());

        $output = $this->listOutput($scheduler);
        $lines = explode("\n", $output);
        $line = static fn (string $needle): string => implode('', array_filter($lines, static fn (string $l): bool => str_contains($l, $needle)));

        self::assertStringContainsString('Next run', $output);
        self::assertMatchesRegularExpression('/nightly-reports.*reports:send.*daily at midnight.*no.*\d{4}-\d{2}-\d{2} 00:00.*-/', $line('nightly-reports'));
        self::assertMatchesRegularExpression('/queue:schedule.*every minute.*yes.*locked/', $line('queue:schedule'));
        self::assertMatchesRegularExpression('/sync:external.*every hour.*yes.*\d{2}:00.*free/', $line('sync:external'));
        self::assertStringContainsString('not within 31 days', $line('never:runs'));
    }

    public function testFileMutexStateIsUnknown(): void
    {
        $dir = sys_get_temp_dir() . '/ez-scheduler-list-' . bin2hex(random_bytes(4));
        $scheduler = new Scheduler(new FileMutex($dir));
        $scheduler->command('queue:schedule')->everyMinute()->withoutOverlapping();

        try {
            self::assertStringContainsString('unknown', $this->listOutput($scheduler));
        } finally {
            rmdir($dir);
        }
    }
}
