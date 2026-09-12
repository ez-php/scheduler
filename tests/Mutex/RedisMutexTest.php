<?php

declare(strict_types=1);

namespace Tests\Mutex;

use EzPhp\Scheduler\Mutex\RedisMutex;
use PHPUnit\Framework\Attributes\CoversClass;
use Redis;
use Tests\TestCase;

/**
 * Class RedisMutexTest
 *
 * Requires a live Redis instance (available via the root Docker Compose stack).
 * Tests are skipped automatically when ext-redis is missing or Redis is
 * unreachable, so the module still tests green standalone without Redis.
 *
 * Uses Redis database 3 to avoid colliding with application data and with
 * ez-php/rate-limiter's tests (database 2).
 *
 * @package Tests\Mutex
 */
#[CoversClass(RedisMutex::class)]
final class RedisMutexTest extends TestCase
{
    private Redis $redis;

    private RedisMutex $mutex;

    private bool $redisConnected = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not available.');
        }

        $host = getenv('REDIS_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('REDIS_PORT') ?: 6379);

        $this->redis = new Redis();

        try {
            $connected = @$this->redis->connect($host, $port);
        } catch (\RedisException) {
            $this->markTestSkipped("Redis is not available at {$host}:{$port}.");
        }

        if (!$connected) {
            $this->markTestSkipped("Redis is not available at {$host}:{$port}.");
        }

        $this->redisConnected = true;
        $this->redis->select(3);
        $this->redis->flushDB();

        $this->mutex = new RedisMutex($this->redis);
    }

    protected function tearDown(): void
    {
        if ($this->redisConnected) {
            $this->redis->flushDB();
        }

        parent::tearDown();
    }

    public function testAcquireReturnsTrueOnFirstCall(): void
    {
        $this->assertTrue($this->mutex->acquire('test-key'));
    }

    public function testAcquireReturnsFalseWhileLockIsHeld(): void
    {
        $this->mutex->acquire('test-key');

        $this->assertFalse($this->mutex->acquire('test-key'));
    }

    public function testReleaseAllowsReAcquire(): void
    {
        $this->mutex->acquire('key');
        $this->mutex->release('key');

        $this->assertTrue($this->mutex->acquire('key'));
    }

    public function testReleaseOnNonAcquiredKeyIsNoOp(): void
    {
        $this->mutex->release('never-acquired');

        $this->assertTrue($this->mutex->acquire('never-acquired'));
    }

    public function testMultipleIndependentKeysCanBeLocked(): void
    {
        $this->assertTrue($this->mutex->acquire('key-a'));
        $this->assertTrue($this->mutex->acquire('key-b'));
        $this->assertFalse($this->mutex->acquire('key-a'));
    }

    public function testASecondMutexInstanceSeesTheSameLock(): void
    {
        // Two instances stand in for two cron processes on different hosts.
        $this->mutex->acquire('shared');

        $other = new RedisMutex($this->redis);

        $this->assertFalse($other->acquire('shared'));
    }

    public function testKeysArePrefixedInRedis(): void
    {
        $this->mutex->acquire('my-key');

        $this->assertSame(1, $this->redis->exists(RedisMutex::KEY_PREFIX . 'my-key'));
    }

    public function testLockCarriesATtl(): void
    {
        $this->mutex->acquire('key');

        $ttl = $this->redis->ttl(RedisMutex::KEY_PREFIX . 'key');

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(RedisMutex::DEFAULT_TTL_SECONDS, $ttl);
    }

    public function testExpiredLockIsReclaimable(): void
    {
        // A TTL of 1 second is the smallest Redis PX/EX granularity worth using
        // here; the crash-recovery path is what this asserts.
        $shortLived = new RedisMutex($this->redis, 1);
        $this->assertTrue($shortLived->acquire('stale'));

        $this->redis->del(RedisMutex::KEY_PREFIX . 'stale'); // simulate expiry

        $this->assertTrue($shortLived->acquire('stale'));
    }

    public function testReleaseOnlyRemovesItsOwnKey(): void
    {
        $this->mutex->acquire('key-a');
        $this->mutex->acquire('key-b');

        $this->mutex->release('key-a');

        $this->assertTrue($this->mutex->acquire('key-a'));
        $this->assertFalse($this->mutex->acquire('key-b'));
    }
}
