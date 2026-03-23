<?php

declare(strict_types=1);

namespace Tests\Mutex;

use EzPhp\Scheduler\Mutex\FileMutex;
use EzPhp\Scheduler\SchedulerException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use Tests\TestCase;

/**
 * Class FileMutexTest
 *
 * @package Tests\Mutex
 */
#[CoversClass(FileMutex::class)]
#[UsesClass(SchedulerException::class)]
final class FileMutexTest extends TestCase
{
    private string $lockDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->lockDir = sys_get_temp_dir() . '/ez-php-scheduler-test-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        // Clean up any leftover lock files
        if (is_dir($this->lockDir)) {
            foreach (glob($this->lockDir . '/*.lock') ?: [] as $file) {
                @unlink($file);
            }

            @rmdir($this->lockDir);
        }

        parent::tearDown();
    }

    public function testAcquireReturnsTrueOnFirstCall(): void
    {
        $mutex = new FileMutex($this->lockDir);

        $this->assertTrue($mutex->acquire('test-key'));

        $mutex->release('test-key');
    }

    public function testReleaseAllowsReAcquire(): void
    {
        $mutex = new FileMutex($this->lockDir);

        $mutex->acquire('key');
        $mutex->release('key');

        $this->assertTrue($mutex->acquire('key'), 'Should be re-acquirable after release');

        $mutex->release('key');
    }

    public function testReleaseOnNonAcquiredKeyIsNoOp(): void
    {
        $mutex = new FileMutex($this->lockDir);
        $mutex->release('never-acquired');

        // Verify the lock dir still exists (no exception was thrown, no side effects)
        $this->assertDirectoryExists($this->lockDir);
    }

    public function testConstructorCreatesDirectoryWhenMissing(): void
    {
        $dir = sys_get_temp_dir() . '/ez-php-scheduler-new-dir-' . uniqid('', true);
        $this->assertDirectoryDoesNotExist($dir);

        new FileMutex($dir);

        $this->assertDirectoryExists($dir);

        @rmdir($dir);
    }

    public function testAcquireReturnsFalseWhenAlreadyLocked(): void
    {
        // Two separate FileMutex instances sharing the same lockDir simulate
        // two concurrent processes (different file handles on the same lock file).
        $mutex1 = new FileMutex($this->lockDir);
        $mutex2 = new FileMutex($this->lockDir);

        $this->assertTrue($mutex1->acquire('shared-key'));

        // mutex2 opens a new file handle and tries LOCK_EX|LOCK_NB → should fail
        $this->assertFalse($mutex2->acquire('shared-key'));

        $mutex1->release('shared-key');
    }

    public function testMultipleIndependentKeysCanBeLocked(): void
    {
        $mutex = new FileMutex($this->lockDir);

        $this->assertTrue($mutex->acquire('key-a'));
        $this->assertTrue($mutex->acquire('key-b'));

        $mutex->release('key-a');
        $mutex->release('key-b');
    }
}
