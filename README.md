# ez-php/scheduler

Cron-based job scheduler for ez-php applications. Register commands with a fluent frequency API, prevent overlapping runs via pluggable mutex drivers (File, Database), and execute due jobs from a single cron entry.

---

## Installation

```bash
composer require ez-php/scheduler
```

---

## Quick Start

Create a schedule definition (e.g. `app/schedule.php`):

```php
use EzPhp\Scheduler\Mutex\DatabaseMutex;
use EzPhp\Scheduler\Scheduler;

$pdo = $app->make(\PDO::class); // or any PDO instance
$scheduler = new Scheduler(new DatabaseMutex($pdo));

$scheduler->command('queue:work')->everyMinute()->withoutOverlapping();
$scheduler->command('cache:prune')->hourly();
$scheduler->command('reports:generate')->daily();
```

Run from a cron entry (once per minute):

```cron
* * * * * php /var/www/html/ez schedule:run
```

In the `schedule:run` command, pass a callable executor that dispatches to your console:

```php
$scheduler->run(new DateTimeImmutable(), static function (string $command) use ($console): void {
    $console->call($command);
});
```

---

## Frequency Methods

All methods are fluent and return `ScheduleEntry` for chaining:

| Method | When due |
|--------|---------|
| `everyMinute()` | Every cron invocation |
| `everyFiveMinutes()` | When `minute % 5 === 0` |
| `hourly()` | At `:00` of every hour |
| `daily()` | At `00:00` |
| `weekly()` | On Sunday at `00:00` |
| `monthly()` | On the 1st of the month at `00:00` |

An entry without a frequency set is **never due**.

---

## Overlap Prevention

Call `withoutOverlapping()` to skip a command if a previous invocation is still running:

```php
$scheduler->command('queue:work')->everyMinute()->withoutOverlapping();
```

Requires a `MutexInterface` passed to the `Scheduler` constructor. A `SchedulerException` is thrown at runtime if `withoutOverlapping()` is used without a mutex configured.

---

## Mutex Drivers

### FileMutex

Uses PHP's `flock(LOCK_EX|LOCK_NB)` on per-command lock files in a configurable directory.

```php
use EzPhp\Scheduler\Mutex\FileMutex;

$mutex = new FileMutex('/var/run/ez-php/locks');
$scheduler = new Scheduler($mutex);
```

- The lock directory is created automatically if it does not exist.
- Lock files are never deleted — their inodes remain stable across runs.
- The lock is tied to the file handle, so a crashed process releases it automatically on the next cron run.
- Suitable for single-server deployments.

### DatabaseMutex

Uses a `scheduler_locks` table (created automatically via `CREATE TABLE IF NOT EXISTS`). Acquiring a lock inserts a row; releasing it deletes the row. A duplicate-key violation signals the lock is already held.

```php
use EzPhp\Scheduler\Mutex\DatabaseMutex;

$mutex = new DatabaseMutex($pdo); // any PDO instance
$scheduler = new Scheduler($mutex);
```

- Compatible with MySQL and SQLite.
- No automatic TTL/expiry — stale rows from crashed processes must be cleaned manually.
- Suitable for multi-server deployments sharing the same database.

---

## API Reference

### `Scheduler`

```php
new Scheduler(?MutexInterface $mutex = null)
```

| Method | Description |
|--------|-------------|
| `command(string $name): ScheduleEntry` | Register a command and return its entry for chaining |
| `all(): list<ScheduleEntry>` | Return all registered entries |
| `dueEntries(DateTimeInterface $time): list<ScheduleEntry>` | Return entries whose predicate matches `$time` |
| `run(DateTimeInterface $time, callable $executor): void` | Execute all due entries via the callable |

### `ScheduleEntry`

| Method | Description |
|--------|-------------|
| `everyMinute(): self` | Due on every invocation |
| `everyFiveMinutes(): self` | Due at minute `:00`, `:05`, `:10`, … |
| `hourly(): self` | Due at minute `:00` |
| `daily(): self` | Due at `00:00` |
| `weekly(): self` | Due on Sunday at `00:00` |
| `monthly(): self` | Due on the 1st at `00:00` |
| `withoutOverlapping(bool $enabled = true): self` | Enable mutex-based skip |
| `isDue(DateTimeInterface $time): bool` | Evaluate the frequency predicate |
| `getCommand(): string` | Return the registered command name |
| `getMutexKey(): string` | Return a stable `sha1`-derived lock key |

### `MutexInterface`

```php
interface MutexInterface
{
    public function acquire(string $key): bool;
    public function release(string $key): void;
}
```

Implement this interface to add custom mutex backends (e.g. Redis, Memcached).

---

## Custom Mutex

```php
use EzPhp\Scheduler\MutexInterface;

final class RedisMutex implements MutexInterface
{
    public function __construct(private readonly \Redis $redis) {}

    public function acquire(string $key): bool
    {
        return (bool) $this->redis->set($key, 1, ['NX', 'EX' => 300]);
    }

    public function release(string $key): void
    {
        $this->redis->del($key);
    }
}
```

---

## Exceptions

`SchedulerException` (extends `RuntimeException`) is thrown when:

- `withoutOverlapping()` is used but no `MutexInterface` was passed to `Scheduler`
- `FileMutex` cannot create the lock directory or open a lock file

Exceptions from the executor callable propagate up after the mutex lock is released (guaranteed via `finally`).
