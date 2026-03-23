<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use EzPhp\Scheduler\MutexInterface;
use EzPhp\Scheduler\ScheduleEntry;
use EzPhp\Scheduler\Scheduler;
use EzPhp\Scheduler\SchedulerException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Class SchedulerTest
 *
 * @package Tests
 */
#[CoversClass(Scheduler::class)]
#[UsesClass(ScheduleEntry::class)]
#[UsesClass(SchedulerException::class)]
final class SchedulerTest extends TestCase
{
    private DateTimeImmutable $alwaysNow;

    protected function setUp(): void
    {
        parent::setUp();
        // A time where everyMinute() is always due
        $this->alwaysNow = new DateTimeImmutable('2025-06-15 12:00:00');
    }

    public function testCommandRegistersEntry(): void
    {
        $scheduler = new Scheduler();
        $entry = $scheduler->command('queue:work');

        $this->assertInstanceOf(ScheduleEntry::class, $entry);
        $this->assertSame('queue:work', $entry->getCommand());
        $this->assertCount(1, $scheduler->all());
    }

    public function testAllReturnsAllRegisteredEntries(): void
    {
        $scheduler = new Scheduler();
        $scheduler->command('a');
        $scheduler->command('b');
        $scheduler->command('c');

        $this->assertCount(3, $scheduler->all());
    }

    public function testDueEntriesFiltersNonDueEntries(): void
    {
        $scheduler = new Scheduler();
        $scheduler->command('always')->everyMinute();
        $scheduler->command('never'); // no frequency set → never due

        $due = $scheduler->dueEntries($this->alwaysNow);

        $this->assertCount(1, $due);
        $this->assertSame('always', $due[0]->getCommand());
    }

    public function testDueEntriesReturnsEmptyWhenNothingDue(): void
    {
        $scheduler = new Scheduler();
        $scheduler->command('never');

        $this->assertSame([], $scheduler->dueEntries($this->alwaysNow));
    }

    public function testRunCallsExecutorForDueEntries(): void
    {
        $scheduler = new Scheduler();
        $scheduler->command('a')->everyMinute();
        $scheduler->command('b')->everyMinute();

        $called = [];
        $scheduler->run($this->alwaysNow, static function (string $cmd) use (&$called): void {
            $called[] = $cmd;
        });

        $this->assertSame(['a', 'b'], $called);
    }

    public function testRunDoesNotCallExecutorForNonDueEntries(): void
    {
        $scheduler = new Scheduler();
        $scheduler->command('never');

        $called = [];
        $scheduler->run($this->alwaysNow, static function (string $cmd) use (&$called): void {
            $called[] = $cmd;
        });

        $this->assertSame([], $called);
    }

    public function testRunThrowsWhenWithoutOverlappingButNoMutex(): void
    {
        $scheduler = new Scheduler(); // no mutex
        $scheduler->command('cmd')->everyMinute()->withoutOverlapping();

        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessageMatches('/no MutexInterface configured/');

        $scheduler->run($this->alwaysNow, static function (string $cmd): void {
        });
    }

    public function testRunSkipsEntryWhenMutexNotAcquired(): void
    {
        $mutex = new class () implements MutexInterface {
            public function acquire(string $key): bool
            {
                return false; // always locked
            }

            public function release(string $key): void
            {
            }
        };

        $scheduler = new Scheduler($mutex);
        $scheduler->command('cmd')->everyMinute()->withoutOverlapping();

        $called = [];
        $scheduler->run($this->alwaysNow, static function (string $cmd) use (&$called): void {
            $called[] = $cmd;
        });

        $this->assertSame([], $called);
    }

    public function testRunExecutesEntryWhenMutexAcquired(): void
    {
        $mutex = new class () implements MutexInterface {
            public function acquire(string $key): bool
            {
                return true;
            }

            public function release(string $key): void
            {
            }
        };

        $scheduler = new Scheduler($mutex);
        $scheduler->command('cmd')->everyMinute()->withoutOverlapping();

        $called = [];
        $scheduler->run($this->alwaysNow, static function (string $cmd) use (&$called): void {
            $called[] = $cmd;
        });

        $this->assertSame(['cmd'], $called);
    }

    public function testRunReleasesLockAfterSuccessfulExecution(): void
    {
        $mutex = new class () implements MutexInterface {
            /** @var list<string> */
            public array $released = [];

            public function acquire(string $key): bool
            {
                return true;
            }

            public function release(string $key): void
            {
                $this->released[] = $key;
            }
        };

        $scheduler = new Scheduler($mutex);
        $entry = $scheduler->command('cmd')->everyMinute()->withoutOverlapping();
        $scheduler->run($this->alwaysNow, static function (string $cmd): void {
        });

        $this->assertSame([$entry->getMutexKey()], $mutex->released);
    }

    public function testRunReleasesLockEvenWhenExecutorThrows(): void
    {
        $mutex = new class () implements MutexInterface {
            /** @var list<string> */
            public array $released = [];

            public function acquire(string $key): bool
            {
                return true;
            }

            public function release(string $key): void
            {
                $this->released[] = $key;
            }
        };

        $scheduler = new Scheduler($mutex);
        $entry = $scheduler->command('cmd')->everyMinute()->withoutOverlapping();

        try {
            $scheduler->run($this->alwaysNow, static function (string $cmd): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame([$entry->getMutexKey()], $mutex->released, 'Lock must be released even on exception');
    }
}
