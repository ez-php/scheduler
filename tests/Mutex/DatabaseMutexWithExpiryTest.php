<?php

declare(strict_types=1);

namespace Tests\Mutex;

use EzPhp\Scheduler\Mutex\DatabaseMutexWithExpiry;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class DatabaseMutexWithExpiryTest
 *
 * Uses an in-memory SQLite database — no external infrastructure required.
 *
 * Expiry is exercised by constructing the mutex with a zero or negative TTL
 * rather than by sleeping: a lock written with `expires_at = now + 0` is
 * already reclaimable on the next acquire, which makes the reclaim path
 * testable without a clock abstraction or a slow test.
 *
 * @package Tests\Mutex
 */
#[CoversClass(DatabaseMutexWithExpiry::class)]
final class DatabaseMutexWithExpiryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    public function testConstructorCreatesLocksTable(): void
    {
        new DatabaseMutexWithExpiry($this->pdo);

        $result = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='scheduler_locks_ttl'"
        );
        $this->assertInstanceOf(\PDOStatement::class, $result);
        $this->assertNotFalse($result->fetch());
    }

    public function testConstructorDoesNotTouchThePlainDatabaseMutexTable(): void
    {
        new DatabaseMutexWithExpiry($this->pdo);

        $result = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='scheduler_locks'"
        );
        $this->assertInstanceOf(\PDOStatement::class, $result);
        $this->assertFalse($result->fetch());
    }

    public function testAcquireReturnsTrueOnFirstCall(): void
    {
        $mutex = new DatabaseMutexWithExpiry($this->pdo);

        $this->assertTrue($mutex->acquire('test-key'));
    }

    public function testAcquireReturnsFalseWhileLockIsStillValid(): void
    {
        $mutex = new DatabaseMutexWithExpiry($this->pdo, 3600);
        $mutex->acquire('test-key');

        $this->assertFalse($mutex->acquire('test-key'));
    }

    public function testExpiredLockIsReclaimedByNextAcquire(): void
    {
        // TTL 0 → the lock is already expired the moment it is written.
        $mutex = new DatabaseMutexWithExpiry($this->pdo, 0);
        $this->assertTrue($mutex->acquire('stale-key'));

        // This is the crash-recovery case: the holder died without releasing.
        $this->assertTrue($mutex->acquire('stale-key'));
    }

    public function testExpiredLockOfOneKeyDoesNotReclaimAnother(): void
    {
        $longLived = new DatabaseMutexWithExpiry($this->pdo, 3600);
        $longLived->acquire('live-key');

        $shortLived = new DatabaseMutexWithExpiry($this->pdo, 0);
        $shortLived->acquire('stale-key');
        $shortLived->acquire('stale-key');

        // Reclaiming 'stale-key' must not have dropped the still-valid lock.
        $this->assertFalse($longLived->acquire('live-key'));
    }

    public function testReleaseRemovesLockRow(): void
    {
        $mutex = new DatabaseMutexWithExpiry($this->pdo);
        $mutex->acquire('test-key');
        $mutex->release('test-key');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM scheduler_locks_ttl WHERE lock_key = ?');
        $stmt->execute(['test-key']);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testReleaseAllowsReAcquire(): void
    {
        $mutex = new DatabaseMutexWithExpiry($this->pdo, 3600);
        $mutex->acquire('key');
        $mutex->release('key');

        $this->assertTrue($mutex->acquire('key'));
    }

    public function testReleaseOnNonAcquiredKeyIsNoOp(): void
    {
        $mutex = new DatabaseMutexWithExpiry($this->pdo);
        $mutex->release('never-acquired');

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM scheduler_locks_ttl');
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testMultipleIndependentKeysCanBeLocked(): void
    {
        $mutex = new DatabaseMutexWithExpiry($this->pdo, 3600);

        $this->assertTrue($mutex->acquire('key-a'));
        $this->assertTrue($mutex->acquire('key-b'));
        $this->assertFalse($mutex->acquire('key-a'));
    }

    public function testConstructorIsIdempotentOnExistingTable(): void
    {
        new DatabaseMutexWithExpiry($this->pdo);
        $mutex = new DatabaseMutexWithExpiry($this->pdo);

        $this->assertTrue($mutex->acquire('idempotent-key'));
    }

    public function testAcquireStoresAnExpiryInTheFuture(): void
    {
        $mutex = new DatabaseMutexWithExpiry($this->pdo, 120);
        $mutex->acquire('key');

        $stmt = $this->pdo->prepare('SELECT expires_at FROM scheduler_locks_ttl WHERE lock_key = ?');
        $stmt->execute(['key']);

        $this->assertGreaterThan(time(), (int) $stmt->fetchColumn());
    }

    public function testNegativeTtlIsTreatedAsAlreadyExpired(): void
    {
        $mutex = new DatabaseMutexWithExpiry($this->pdo, -60);

        $this->assertTrue($mutex->acquire('key'));
        $this->assertTrue($mutex->acquire('key'));
    }
}
