<?php

declare(strict_types=1);

namespace Tests\Console;

use EzPhp\Application\Application;
use EzPhp\Console\Console;
use EzPhp\Scheduler\Console\SchedulerRunCommand;
use EzPhp\Scheduler\ScheduleEntry;
use EzPhp\Scheduler\Scheduler;
use EzPhp\Scheduler\SchedulerException;
use EzPhp\Testing\ApplicationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\Support\SchedulerProbeCommand;
use Tests\Support\SchedulerProbeProvider;

#[CoversClass(SchedulerRunCommand::class)]
#[UsesClass(Scheduler::class)]
#[UsesClass(ScheduleEntry::class)]
#[UsesClass(SchedulerException::class)]
final class SchedulerRunCommandTest extends ApplicationTestCase
{
    protected function setUp(): void
    {
        SchedulerProbeCommand::$calls = [];

        parent::setUp();
    }

    protected function tearDown(): void
    {
        SchedulerProbeProvider::$commands = [];
        SchedulerProbeCommand::$calls = [];

        parent::tearDown();
    }

    /**
     * Temporary app root whose config points the database at in-memory SQLite:
     * building the Console constructs the framework's migrate commands, which need a DB.
     */
    protected function getBasePath(): string
    {
        $path = parent::getBasePath();
        file_put_contents(
            $path . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'db.php',
            "<?php return ['driver' => 'sqlite', 'database' => ':memory:'];",
        );

        return $path;
    }

    protected function configureApplication(Application $app): void
    {
        $app->register(SchedulerProbeProvider::class);
        $app->registerCommand(SchedulerProbeCommand::class);
        $app->registerCommand(SchedulerRunCommand::class);
    }

    /**
     * @return array{0: int, 1: string}
     */
    private function runSchedulerRun(): array
    {
        $console = $this->app()->make(Console::class);

        ob_start();
        $exit = $console->run(['ez', 'scheduler:run']);
        $output = (string) ob_get_clean();

        return [$exit, $output];
    }

    public function testIsNamedDistinctlyFromTheFrameworkScheduleRun(): void
    {
        $command = $this->app()->make(SchedulerRunCommand::class);

        self::assertSame('scheduler:run', $command->getName());
    }

    public function testDueEntriesRunThroughTheApplicationConsoleWithTheirArguments(): void
    {
        SchedulerProbeProvider::$commands = ['probe:record', 'probe:record --queue=emails 5'];

        [$exit, $output] = $this->runSchedulerRun();

        self::assertSame(0, $exit);
        self::assertSame([[], ['--queue=emails', '5']], SchedulerProbeCommand::$calls);
        self::assertStringContainsString('probe:record --queue=emails 5', $output);
    }

    public function testNothingDueReportsItAndSucceeds(): void
    {
        [$exit, $output] = $this->runSchedulerRun();

        self::assertSame(0, $exit);
        self::assertSame([], SchedulerProbeCommand::$calls);
        self::assertStringContainsString('No scheduled commands are due.', $output);
    }

    public function testFailingEntryStopsTheRunAndReturnsOne(): void
    {
        SchedulerProbeProvider::$commands = ['probe:record --fail', 'probe:record after'];

        [$exit] = $this->runSchedulerRun();

        self::assertSame(1, $exit);
        self::assertSame([['--fail']], SchedulerProbeCommand::$calls);
    }

    public function testUnknownScheduledCommandFailsTheRun(): void
    {
        SchedulerProbeProvider::$commands = ['does:not-exist'];

        [$exit] = $this->runSchedulerRun();

        self::assertSame(1, $exit);
    }
}
