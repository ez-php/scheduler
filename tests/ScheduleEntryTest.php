<?php

declare(strict_types=1);

namespace Tests;

use DateTimeImmutable;
use EzPhp\Scheduler\ScheduleEntry;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Class ScheduleEntryTest
 *
 * @package Tests
 */
#[CoversClass(ScheduleEntry::class)]
final class ScheduleEntryTest extends TestCase
{
    public function testGetCommandReturnsName(): void
    {
        $entry = new ScheduleEntry('queue:work');

        $this->assertSame('queue:work', $entry->getCommand());
    }

    public function testDefaultIsNeverDue(): void
    {
        $entry = new ScheduleEntry('cache:prune');

        $this->assertFalse($entry->isDue(new DateTimeImmutable()));
        $this->assertSame('not scheduled', $entry->getFrequencyDescription());
    }

    public function testEveryMinuteIsAlwaysDue(): void
    {
        $entry = (new ScheduleEntry('cmd'))->everyMinute();

        $this->assertTrue($entry->isDue(new DateTimeImmutable('2025-01-01 12:07:00')));
        $this->assertSame('every minute', $entry->getFrequencyDescription());
    }

    public function testEveryFiveMinutesDueAtMultiplesOfFive(): void
    {
        $entry = (new ScheduleEntry('cmd'))->everyFiveMinutes();

        $this->assertTrue($entry->isDue(new DateTimeImmutable('2025-01-01 12:00:00')));
        $this->assertTrue($entry->isDue(new DateTimeImmutable('2025-01-01 12:05:00')));
        $this->assertTrue($entry->isDue(new DateTimeImmutable('2025-01-01 12:55:00')));
        $this->assertSame('every five minutes', $entry->getFrequencyDescription());
    }

    public function testEveryFiveMinutesNotDueAtNonMultiples(): void
    {
        $entry = (new ScheduleEntry('cmd'))->everyFiveMinutes();

        $this->assertFalse($entry->isDue(new DateTimeImmutable('2025-01-01 12:07:00')));
        $this->assertFalse($entry->isDue(new DateTimeImmutable('2025-01-01 12:01:00')));
    }

    public function testHourlyDueAtMinuteZero(): void
    {
        $entry = (new ScheduleEntry('cmd'))->hourly();

        $this->assertTrue($entry->isDue(new DateTimeImmutable('2025-01-01 12:00:00')));
        $this->assertFalse($entry->isDue(new DateTimeImmutable('2025-01-01 12:01:00')));
        $this->assertSame('every hour', $entry->getFrequencyDescription());
    }

    public function testDailyDueAtMidnightOnly(): void
    {
        $entry = (new ScheduleEntry('cmd'))->daily();

        $this->assertTrue($entry->isDue(new DateTimeImmutable('2025-01-01 00:00:00')));
        $this->assertFalse($entry->isDue(new DateTimeImmutable('2025-01-01 01:00:00')));
        $this->assertFalse($entry->isDue(new DateTimeImmutable('2025-01-01 00:01:00')));
        $this->assertSame('daily at midnight', $entry->getFrequencyDescription());
    }

    public function testWeeklyDueOnSundayAtMidnight(): void
    {
        $entry = (new ScheduleEntry('cmd'))->weekly();

        // 2025-01-05 is a Sunday
        $this->assertTrue($entry->isDue(new DateTimeImmutable('2025-01-05 00:00:00')));
        // Monday
        $this->assertFalse($entry->isDue(new DateTimeImmutable('2025-01-06 00:00:00')));
        // Sunday but not midnight
        $this->assertFalse($entry->isDue(new DateTimeImmutable('2025-01-05 01:00:00')));
        $this->assertSame('weekly on Sunday at midnight', $entry->getFrequencyDescription());
    }

    public function testMonthlyDueOnFirstAtMidnight(): void
    {
        $entry = (new ScheduleEntry('cmd'))->monthly();

        $this->assertTrue($entry->isDue(new DateTimeImmutable('2025-03-01 00:00:00')));
        $this->assertFalse($entry->isDue(new DateTimeImmutable('2025-03-02 00:00:00')));
        $this->assertFalse($entry->isDue(new DateTimeImmutable('2025-03-01 01:00:00')));
        $this->assertSame('monthly on the 1st at midnight', $entry->getFrequencyDescription());
    }

    public function testWithoutOverlappingDefaultsFalse(): void
    {
        $entry = new ScheduleEntry('cmd');

        $this->assertFalse($entry->shouldSkipIfOverlapping());
    }

    public function testWithoutOverlappingSetsTrue(): void
    {
        $entry = (new ScheduleEntry('cmd'))->withoutOverlapping();

        $this->assertTrue($entry->shouldSkipIfOverlapping());
    }

    public function testWithoutOverlappingCanBeDisabled(): void
    {
        $entry = (new ScheduleEntry('cmd'))->withoutOverlapping()->withoutOverlapping(false);

        $this->assertFalse($entry->shouldSkipIfOverlapping());
    }

    public function testGetMutexKeyIsStableForSameCommand(): void
    {
        $a = new ScheduleEntry('queue:work');
        $b = new ScheduleEntry('queue:work');

        $this->assertSame($a->getMutexKey(), $b->getMutexKey());
    }

    public function testGetMutexKeyDiffersForDifferentCommands(): void
    {
        $a = new ScheduleEntry('queue:work');
        $b = new ScheduleEntry('cache:prune');

        $this->assertNotSame($a->getMutexKey(), $b->getMutexKey());
    }

    public function testFrequencyMethodsReturnSelf(): void
    {
        $entry = new ScheduleEntry('cmd');

        $this->assertSame($entry, $entry->everyMinute());
        $this->assertSame($entry, $entry->everyFiveMinutes());
        $this->assertSame($entry, $entry->hourly());
        $this->assertSame($entry, $entry->daily());
        $this->assertSame($entry, $entry->weekly());
        $this->assertSame($entry, $entry->monthly());
        $this->assertSame($entry, $entry->withoutOverlapping());
    }
}
