<?php

declare(strict_types=1);

namespace Tests\Mutex;

use EzPhp\Cache\ArrayDriver;
use EzPhp\Cache\ArrayLock;
use EzPhp\Cache\FileDriver;
use EzPhp\Scheduler\Mutex\CacheMutex;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * CacheMutex over ez-php/cache locks. ArrayDriver's lock registry is shared
 * per process, so two CacheMutex instances behave like two cron processes;
 * FileDriver covers a lock that lives outside the process memory.
 *
 * @package Tests\Mutex
 */
#[CoversClass(CacheMutex::class)]
final class CacheMutexTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();
        ArrayLock::reset();
    }

    protected function tearDown(): void
    {
        ArrayLock::reset();

        if ($this->dir !== '') {
            array_map('unlink', glob($this->dir . '/*') ?: []);
            @rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function testSecondAcquireOfTheSameKeyFails(): void
    {
        $first = new CacheMutex(new ArrayDriver());
        $second = new CacheMutex(new ArrayDriver());

        $this->assertTrue($first->acquire('report'));
        $this->assertFalse($second->acquire('report'));
        $this->assertTrue($second->acquire('other'), 'keys are independent');
    }

    public function testReleaseFreesTheKey(): void
    {
        $first = new CacheMutex(new ArrayDriver());
        $second = new CacheMutex(new ArrayDriver());

        $first->acquire('report');
        $first->release('report');

        $this->assertTrue($second->acquire('report'));
    }

    public function testReleasingAKeyThisInstanceNeverAcquiredIsANoOp(): void
    {
        $holder = new CacheMutex(new ArrayDriver());
        $stranger = new CacheMutex(new ArrayDriver());

        $holder->acquire('report');
        $stranger->release('report');

        $this->assertFalse((new CacheMutex(new ArrayDriver()))->acquire('report'));
    }

    public function testExpiredLockCanBeTakenOverAndTheOldHolderCannotDeleteIt(): void
    {
        $stale = new CacheMutex(new ArrayDriver(), ttlSeconds: -1);
        $this->assertTrue($stale->acquire('report'));

        $fresh = new CacheMutex(new ArrayDriver());
        $this->assertTrue($fresh->acquire('report'));

        $stale->release('report');
        $this->assertFalse((new CacheMutex(new ArrayDriver()))->acquire('report'));
    }

    public function testLockKeysAreNamespaced(): void
    {
        $cache = new ArrayDriver();
        (new CacheMutex($cache))->acquire('report');

        $this->assertTrue($cache->lock('report')->acquire(), 'an application lock named "report" is a different key');
        $this->assertFalse($cache->lock(CacheMutex::KEY_PREFIX . 'report')->acquire());
    }

    public function testWorksOnTheFileDriver(): void
    {
        $this->dir = sys_get_temp_dir() . '/ez-scheduler-cache-mutex-' . uniqid('', true);
        $mutex = new CacheMutex(new FileDriver($this->dir));

        $this->assertTrue($mutex->acquire('report'));
        $mutex->release('report');
        $this->assertTrue($mutex->acquire('report'));
        $mutex->release('report');
    }
}
