<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use EzPhp\Scheduler\Mutex\DatabaseMutex;
use EzPhp\Scheduler\Mutex\FileMutex;
use EzPhp\Scheduler\ScheduleEntry;
use EzPhp\Scheduler\Scheduler;
use EzPhp\Scheduler\SchedulerException;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use RuntimeException;
use Tests\TestCase;

/**
 * Integration tests for Scheduler mutex behaviour under concurrent workers.
 *
 * Concurrency is simulated within a single PHP process by using two separate
 * mutex instances that share the same underlying resource (same PDO connection
 * for DatabaseMutex; same lock-file directory for FileMutex). This faithfully
 * reproduces the race condition: "worker A holds the lock — will worker B skip?"
 *
 * Scenarios covered:
 *   - Second worker skips a command when the first holds the DatabaseMutex lock
 *   - Second worker runs after the first releases the lock
 *   - Lock is released after successful execution (idempotent re-runs)
 *   - Lock is released even when the executor throws
 *   - Independent commands share no lock — only the locked command is skipped
 *   - FileMutex: same scenarios using file-based locking
 *   - Entries without withoutOverlapping() always run regardless of any held lock
 */
#[CoversClass(Scheduler::class)]
#[CoversClass(DatabaseMutex::class)]
#[CoversClass(FileMutex::class)]
#[UsesClass(ScheduleEntry::class)]
#[UsesClass(SchedulerException::class)]
final class MutexConcurrencyTest extends TestCase
{
    private DateTimeImmutable $now;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        // 12:00 — everyMinute() is always due at any time
        $this->now = new DateTimeImmutable('2025-06-15 12:00:00');
    }

    // ─── DatabaseMutex ────────────────────────────────────────────────────────

    /**
     * When worker A holds the lock, worker B's scheduler must skip the command.
     *
     * @return void
     */
    public function testDatabaseMutexPreventsOverlappingExecution(): void
    {
        $pdo = $this->makeSqlite();

        $mutexA = new DatabaseMutex($pdo); // simulates worker A
        $mutexB = new DatabaseMutex($pdo); // simulates worker B (same DB)

        $scheduler = new Scheduler($mutexB);
        $entry = $scheduler->command('queue:work')->everyMinute()->withoutOverlapping();

        $mutexA->acquire($entry->getMutexKey()); // worker A acquires

        $called = [];
        $scheduler->run($this->now, static function (string $cmd) use (&$called): void {
            $called[] = $cmd;
        });

        $this->assertSame([], $called, 'Command must be skipped while lock is held by another worker');

        $mutexA->release($entry->getMutexKey());
    }

    /**
     * After worker A releases the lock, worker B's scheduler must execute the command.
     *
     * @return void
     */
    public function testDatabaseMutexAllowsExecutionAfterLockReleased(): void
    {
        $pdo = $this->makeSqlite();

        $mutexA = new DatabaseMutex($pdo);
        $mutexB = new DatabaseMutex($pdo);

        $scheduler = new Scheduler($mutexB);
        $entry = $scheduler->command('cache:prune')->everyMinute()->withoutOverlapping();

        $mutexA->acquire($entry->getMutexKey());
        $mutexA->release($entry->getMutexKey()); // worker A done

        $called = [];
        $scheduler->run($this->now, static function (string $cmd) use (&$called): void {
            $called[] = $cmd;
        });

        $this->assertSame(['cache:prune'], $called);
    }

    /**
     * After a successful run the lock is released, allowing the same scheduler
     * instance to run again on the next cron tick.
     *
     * @return void
     */
    public function testDatabaseMutexLockIsReleasedAfterSuccessfulExecution(): void
    {
        $pdo = $this->makeSqlite();

        $scheduler = new Scheduler(new DatabaseMutex($pdo));
        $scheduler->command('send:emails')->everyMinute()->withoutOverlapping();

        $scheduler->run($this->now, static function (string $cmd): void {
        });

        // Second run on same instance — lock must be free
        $called = [];
        $scheduler->run($this->now, static function (string $cmd) use (&$called): void {
            $called[] = $cmd;
        });

        $this->assertSame(['send:emails'], $called, 'Lock must be released after successful execution');
    }

    /**
     * The lock must be released even when the executor closure throws an exception.
     *
     * @return void
     */
    public function testDatabaseMutexLockIsReleasedEvenWhenExecutorThrows(): void
    {
        $pdo = $this->makeSqlite();

        $scheduler = new Scheduler(new DatabaseMutex($pdo));
        $scheduler->command('failing:command')->everyMinute()->withoutOverlapping();

        try {
            $scheduler->run($this->now, static function (string $cmd): void {
                throw new RuntimeException('Command failed');
            });
        } catch (RuntimeException) {
            // expected — exception propagates through run()
        }

        // Lock must be free for the next invocation
        $called = [];
        $scheduler->run($this->now, static function (string $cmd) use (&$called): void {
            $called[] = $cmd;
        });

        $this->assertSame(['failing:command'], $called, 'Lock must be released even when executor throws');
    }

    /**
     * Only the specifically locked command is skipped; other independently-keyed
     * commands continue to run normally.
     *
     * @return void
     */
    public function testDatabaseMutexOnlySkipsTheLockedCommand(): void
    {
        $pdo = $this->makeSqlite();

        $mutexA = new DatabaseMutex($pdo);
        $mutexB = new DatabaseMutex($pdo);

        $scheduler = new Scheduler($mutexB);
        $entryA = $scheduler->command('slow:import')->everyMinute()->withoutOverlapping();
        $scheduler->command('fast:ping')->everyMinute()->withoutOverlapping();

        // Worker A holds only the lock for 'slow:import'
        $mutexA->acquire($entryA->getMutexKey());

        $called = [];
        $scheduler->run($this->now, static function (string $cmd) use (&$called): void {
            $called[] = $cmd;
        });

        $this->assertNotContains('slow:import', $called, 'Locked command must be skipped');
        $this->assertContains('fast:ping', $called, 'Unlocked command must still run');

        $mutexA->release($entryA->getMutexKey());
    }

    /**
     * An entry declared without withoutOverlapping() always runs, even when
     * another entry's lock is held.
     *
     * @return void
     */
    public function testEntriesWithoutOverlappingRunRegardlessOfOtherLocks(): void
    {
        $pdo = $this->makeSqlite();

        $mutexA = new DatabaseMutex($pdo);
        $mutexB = new DatabaseMutex($pdo);

        $scheduler = new Scheduler($mutexB);
        $locked = $scheduler->command('slow:import')->everyMinute()->withoutOverlapping();
        $scheduler->command('fast:health')->everyMinute(); // no overlap prevention

        $mutexA->acquire($locked->getMutexKey());

        $called = [];
        $scheduler->run($this->now, static function (string $cmd) use (&$called): void {
            $called[] = $cmd;
        });

        $this->assertNotContains('slow:import', $called);
        $this->assertContains('fast:health', $called, 'Non-overlapping entry always executes');

        $mutexA->release($locked->getMutexKey());
    }

    // ─── FileMutex ────────────────────────────────────────────────────────────

    /**
     * FileMutex with two instances sharing the same lock directory simulates
     * two concurrent processes: the second must skip when the first holds the lock.
     *
     * @return void
     */
    public function testFileMutexPreventsOverlappingExecution(): void
    {
        $lockDir = $this->makeTempLockDir();

        try {
            $mutexA = new FileMutex($lockDir);
            $mutexB = new FileMutex($lockDir);

            $scheduler = new Scheduler($mutexB);
            $entry = $scheduler->command('import:csv')->everyMinute()->withoutOverlapping();

            $mutexA->acquire($entry->getMutexKey());

            $called = [];
            $scheduler->run($this->now, static function (string $cmd) use (&$called): void {
                $called[] = $cmd;
            });

            $this->assertSame([], $called, 'Command must be skipped while FileMutex lock is held');

            $mutexA->release($entry->getMutexKey());
        } finally {
            $this->cleanupLockDir($lockDir);
        }
    }

    /**
     * FileMutex: lock is released after a successful run, allowing a second invocation.
     *
     * @return void
     */
    public function testFileMutexLockIsReleasedAfterSuccessfulExecution(): void
    {
        $lockDir = $this->makeTempLockDir();

        try {
            $scheduler = new Scheduler(new FileMutex($lockDir));
            $scheduler->command('generate:reports')->everyMinute()->withoutOverlapping();

            $scheduler->run($this->now, static function (string $cmd): void {
            });

            $called = [];
            $scheduler->run($this->now, static function (string $cmd) use (&$called): void {
                $called[] = $cmd;
            });

            $this->assertSame(['generate:reports'], $called, 'Lock must be released after successful run');
        } finally {
            $this->cleanupLockDir($lockDir);
        }
    }

    /**
     * FileMutex: lock is released even when the executor throws.
     *
     * @return void
     */
    public function testFileMutexLockIsReleasedEvenWhenExecutorThrows(): void
    {
        $lockDir = $this->makeTempLockDir();

        try {
            $scheduler = new Scheduler(new FileMutex($lockDir));
            $scheduler->command('failing:task')->everyMinute()->withoutOverlapping();

            try {
                $scheduler->run($this->now, static function (string $cmd): void {
                    throw new RuntimeException('Task failed');
                });
            } catch (RuntimeException) {
                // expected
            }

            $called = [];
            $scheduler->run($this->now, static function (string $cmd) use (&$called): void {
                $called[] = $cmd;
            });

            $this->assertSame(['failing:task'], $called, 'FileMutex lock must be released even on exception');
        } finally {
            $this->cleanupLockDir($lockDir);
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Create an in-memory SQLite PDO connection.
     *
     * @return PDO
     */
    private function makeSqlite(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    /**
     * Create a unique temporary directory for FileMutex lock files.
     *
     * @return string
     */
    private function makeTempLockDir(): string
    {
        return sys_get_temp_dir() . '/ez-php-scheduler-mutex-' . uniqid('', true);
    }

    /**
     * Remove all lock files and the directory created by makeTempLockDir().
     *
     * @param string $lockDir
     *
     * @return void
     */
    private function cleanupLockDir(string $lockDir): void
    {
        foreach (glob($lockDir . '/*.lock') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($lockDir);
    }
}
