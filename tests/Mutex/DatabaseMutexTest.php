<?php

declare(strict_types=1);

namespace Tests\Mutex;

use EzPhp\Scheduler\Mutex\DatabaseMutex;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;
use Tests\TestCase;

/**
 * Class DatabaseMutexTest
 *
 * Uses an in-memory SQLite database — no external infrastructure required.
 *
 * @package Tests\Mutex
 */
#[CoversClass(DatabaseMutex::class)]
final class DatabaseMutexTest extends TestCase
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
        new DatabaseMutex($this->pdo);

        $result = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='scheduler_locks'");
        $this->assertInstanceOf(\PDOStatement::class, $result);
        $this->assertNotFalse($result->fetch());
    }

    public function testAcquireReturnsTrueOnFirstCall(): void
    {
        $mutex = new DatabaseMutex($this->pdo);

        $this->assertTrue($mutex->acquire('test-key'));
    }

    public function testAcquireReturnsFalseWhenKeyAlreadyLocked(): void
    {
        $mutex = new DatabaseMutex($this->pdo);
        $mutex->acquire('test-key');

        $this->assertFalse($mutex->acquire('test-key'));
    }

    public function testReleaseRemovesLockRow(): void
    {
        $mutex = new DatabaseMutex($this->pdo);
        $mutex->acquire('test-key');
        $mutex->release('test-key');

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM scheduler_locks WHERE lock_key = ?');
        $stmt->execute(['test-key']);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testReleaseAllowsReAcquire(): void
    {
        $mutex = new DatabaseMutex($this->pdo);
        $mutex->acquire('key');
        $mutex->release('key');

        $this->assertTrue($mutex->acquire('key'));
    }

    public function testReleaseOnNonAcquiredKeyIsNoOp(): void
    {
        $mutex = new DatabaseMutex($this->pdo);
        $mutex->release('never-acquired');

        // Verify the table remains intact (no exception was thrown)
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM scheduler_locks');
        $this->assertInstanceOf(\PDOStatement::class, $stmt);
        $this->assertSame(0, (int) $stmt->fetchColumn());
    }

    public function testMultipleIndependentKeysCanBeLocked(): void
    {
        $mutex = new DatabaseMutex($this->pdo);

        $this->assertTrue($mutex->acquire('key-a'));
        $this->assertTrue($mutex->acquire('key-b'));

        $mutex->release('key-a');
        $mutex->release('key-b');
    }

    public function testConstructorIsIdempotentOnExistingTable(): void
    {
        new DatabaseMutex($this->pdo);
        // Second instantiation must not throw (IF NOT EXISTS)
        $mutex = new DatabaseMutex($this->pdo);
        $this->assertTrue($mutex->acquire('idempotent-key'));
    }
}
