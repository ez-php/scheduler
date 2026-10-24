<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Application\Application;
use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\CacheInterface;
use EzPhp\Config\Config;
use EzPhp\Console\Console;
use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Scheduler\Console\SchedulerListCommand;
use EzPhp\Scheduler\Console\SchedulerRunCommand;
use EzPhp\Scheduler\Mutex\CacheMutex;
use EzPhp\Scheduler\Mutex\DatabaseMutex;
use EzPhp\Scheduler\Mutex\DatabaseMutexWithExpiry;
use EzPhp\Scheduler\Mutex\FileMutex;
use EzPhp\Scheduler\ScheduleEntry;
use EzPhp\Scheduler\Scheduler;
use EzPhp\Scheduler\SchedulerException;
use EzPhp\Scheduler\SchedulerServiceProvider;
use EzPhp\Testing\ApplicationTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;

#[CoversClass(SchedulerServiceProvider::class)]
#[UsesClass(Scheduler::class)]
#[UsesClass(ScheduleEntry::class)]
#[UsesClass(SchedulerException::class)]
#[UsesClass(FileMutex::class)]
#[UsesClass(DatabaseMutex::class)]
#[UsesClass(DatabaseMutexWithExpiry::class)]
#[UsesClass(CacheMutex::class)]
#[UsesClass(\EzPhp\Scheduler\Mutex\RedisMutex::class)]
#[UsesClass(SchedulerListCommand::class)]
#[UsesClass(SchedulerRunCommand::class)]
final class SchedulerServiceProviderTest extends ApplicationTestCase
{
    private string $lockDir = '';

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
        $app->register(SchedulerServiceProvider::class);
    }

    protected function tearDown(): void
    {
        if ($this->lockDir !== '' && is_dir($this->lockDir)) {
            array_map('unlink', glob($this->lockDir . '/*') ?: []);
            rmdir($this->lockDir);
        }

        parent::tearDown();
    }

    /**
     * Replace the configuration before the Scheduler is first resolved.
     *
     * @param array<string, mixed> $scheduler
     */
    private function useSchedulerConfig(array $scheduler): void
    {
        $this->app()->instance(ConfigInterface::class, new Config(['scheduler' => $scheduler]));
    }

    /**
     * Run one withoutOverlapping() entry through the bound Scheduler.
     */
    private function runOverlappingEntry(): bool
    {
        $scheduler = $this->app()->make(Scheduler::class);
        $scheduler->command('report:send')->everyMinute()->withoutOverlapping();
        $ran = false;
        $scheduler->run(new \DateTimeImmutable(), static function () use (&$ran): void {
            $ran = true;
        });

        return $ran;
    }

    private function tableExists(string $table): bool
    {
        $pdo = $this->app()->make(DatabaseInterface::class)->getPdo();
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return $stmt->fetchColumn() !== false;
    }

    public function testSchedulerIsSharedSoEntriesFromOtherProvidersSurvive(): void
    {
        $this->lockDir = sys_get_temp_dir() . '/ez-scheduler-provider-' . bin2hex(random_bytes(4));
        $this->useSchedulerConfig(['file' => ['path' => $this->lockDir]]);

        $this->app()->make(Scheduler::class)->command('cache:prune')->daily();

        self::assertSame($this->app()->make(Scheduler::class), $this->app()->make(Scheduler::class));
        self::assertCount(1, $this->app()->make(Scheduler::class)->all());
    }

    public function testDefaultsToAFileMutexInTheConfiguredDirectory(): void
    {
        $this->lockDir = sys_get_temp_dir() . '/ez-scheduler-provider-' . bin2hex(random_bytes(4));
        $this->useSchedulerConfig(['file' => ['path' => $this->lockDir]]);

        self::assertTrue($this->runOverlappingEntry());
        self::assertNotSame([], glob($this->lockDir . '/*.lock'));
    }

    public function testDatabaseMutex(): void
    {
        $this->useSchedulerConfig(['mutex' => 'database']);

        self::assertTrue($this->runOverlappingEntry());
        self::assertTrue($this->tableExists('scheduler_locks'));
    }

    public function testDatabaseExpiryMutex(): void
    {
        $this->useSchedulerConfig(['mutex' => 'database_expiry', 'ttl' => 60]);

        self::assertTrue($this->runOverlappingEntry());
        self::assertTrue($this->tableExists('scheduler_locks_ttl'));
    }

    public function testCacheMutexUsesTheBoundCache(): void
    {
        $this->useSchedulerConfig(['mutex' => 'cache']);
        $this->app()->instance(CacheInterface::class, new ArrayDriver());

        self::assertTrue($this->runOverlappingEntry());
    }

    public function testNoneLeavesTheSchedulerWithoutMutex(): void
    {
        $this->useSchedulerConfig(['mutex' => 'none']);

        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessage('no MutexInterface configured');

        $this->runOverlappingEntry();
    }

    public function testUnreachableRedisDoesNotBreakTheConsoleAndFailsClosed(): void
    {
        if (!extension_loaded('redis')) {
            self::markTestSkipped('ext-redis is not available.');
        }

        $this->useSchedulerConfig(['mutex' => 'redis', 'redis' => ['host' => '127.0.0.1', 'port' => 1]]);

        $this->app()->make(Console::class);

        // RedisMutex cannot acquire without a server, so the overlapping entry is skipped.
        self::assertFalse($this->runOverlappingEntry());
    }

    public function testUnknownMutexIsRejected(): void
    {
        $this->useSchedulerConfig(['mutex' => 'zookeeper']);

        $this->expectException(SchedulerException::class);
        $this->expectExceptionMessage('Unknown scheduler.mutex "zookeeper"');

        $this->app()->make(Scheduler::class);
    }

    public function testRegistersRunAndListCommands(): void
    {
        $this->useSchedulerConfig(['mutex' => 'none']);
        $console = $this->app()->make(Console::class);

        ob_start();
        $listExit = $console->run(['ez', 'scheduler:list']);
        $runExit = $console->run(['ez', 'scheduler:run']);
        $output = (string) ob_get_clean();

        self::assertSame(0, $listExit);
        self::assertSame(0, $runExit);
        self::assertStringContainsString('No scheduled commands registered.', $output);
        self::assertStringContainsString('No scheduled commands are due.', $output);
    }
}
