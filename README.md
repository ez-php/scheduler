# ez-php/scheduler

Cron-based job scheduler for ez-php applications. Register commands with a fluent frequency API, prevent overlapping runs via pluggable mutex drivers (File, Database), and execute due jobs from a single cron entry.

---

## Installation

```bash
composer require ez-php/scheduler
```

---

## Quick Start

Bind a configured `Scheduler` in a service provider's `register()`:

```php
use EzPhp\Scheduler\Mutex\FileMutex;
use EzPhp\Scheduler\Scheduler;

$this->app->bind(Scheduler::class, function (): Scheduler {
    $scheduler = new Scheduler(new FileMutex(sys_get_temp_dir() . '/ez-schedule-locks'));

    $scheduler->command('queue:work --max-jobs=100')->everyMinute()->withoutOverlapping();
    $scheduler->command('cache:prune')->hourly();
    $scheduler->command('reports:generate')->daily();

    return $scheduler;
});
```

Register the `scheduler:run` command before bootstrap (e.g. in `public/index.php` and `ez`):

```php
$app->registerCommand(\EzPhp\Scheduler\Console\SchedulerRunCommand::class);
```

Run it from a cron entry, once per minute:

```cron
* * * * * php /var/www/html/ez scheduler:run
```

`scheduler:run` runs every due entry through the application's console — the entry
string is split on whitespace, so `'queue:work --max-jobs=100'` calls `queue:work` with
`--max-jobs=100` — honouring `withoutOverlapping()` and the optional logger. It stops at
the first entry that fails (non-zero exit or exception) and exits `1`.

It is deliberately named `scheduler:run`: the framework's own `schedule:run` drives the
framework's simpler `EzPhp\Console\Schedule\Scheduler` (no overlap prevention, no
`cron()`), not this package's. Use one or the other, not both from cron.

Outside an ez-php application, call `run()` with your own executor:

```php
$scheduler->run(new DateTimeImmutable(), static function (string $command): void {
    // dispatch $command however your application runs commands; throw on failure
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
| `cron(string $expression)` | Matches a five-field cron expression (`minute hour dom month dow`) |

An entry without a frequency set is **never due**.

### Explicit cron expressions

For schedules the predefined helpers don't cover, pass a standard five-field
expression directly:

```php
$scheduler->command('reports:weekly')->cron('30 6 * * 1'); // 06:30 every Monday
$scheduler->command('sync:external')->cron('*/15 * * * *'); // every 15 minutes
```

Supported field syntax per position: `*` (any), `N` (exact value), `*/N` (every
N steps starting from 0). Ranges (`1-5`) and lists (`1,3,5`) are not supported —
compose several `command()` calls, or use one of the predefined frequency
methods, if you need those. A malformed expression (not exactly five
space-separated fields) is simply never due, same as an entry with no
frequency set at all.

---

## Reconciling with `ez-php/queue`'s own scheduler

`ez-php/queue` ships an independent job-class-based scheduler
(`Scheduling\Scheduler` + `Scheduling\ScheduledTask`, driven by the
`queue:schedule` console command) with its own cron-expression matching but
**no overlap prevention** — nothing stops two overlapping `queue:schedule`
cron ticks from both matching the same due task and double-pushing the same
job, if a tick ever runs long enough to still be executing when the next
one starts.

The two packages are not merged — `ez-php/queue`'s job-class model
(`ScheduledTask::createJob()`) and this package's console-command model
(`ScheduleEntry::getCommand()`) are different enough that unifying them
would be a real merge, which is explicitly out of scope. Instead, register
`queue:schedule` itself as a single `withoutOverlapping()` entry here, so
this package's mutex protects the entire due-job-pushing step as one atomic
unit, regardless of how many individual `ScheduledTask`s it evaluates
internally:

```php
$scheduler->command('queue:schedule')->everyMinute()->withoutOverlapping();
```

Then point your system cron at **this** package's `scheduler:run` only —
remove any separate `* * * * * ez queue:schedule` cron line, since this
entry now invokes it (mutex-guarded) on your behalf:

```cron
* * * * * php /var/www/html/ez scheduler:run
```

This is pure integration glue: `queue:schedule`'s own cron-matching and job
dispatch logic (`ez-php/queue`'s `Scheduling\Scheduler::dueJobs()`) is
unchanged and still runs exactly as before — this package's mutex now simply
wraps the single point where it used to be invoked directly by cron, closing
the "queue:schedule takes over a minute, cron overlaps it" race window
without either package depending on the other.

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
- No automatic TTL/expiry — stale rows from crashed processes must be cleaned manually. Use `DatabaseMutexWithExpiry` if that matters.
- Suitable for multi-server deployments sharing the same database.

### DatabaseMutexWithExpiry

Same idea as `DatabaseMutex`, but each lock carries an expiry timestamp. If the
process holding a lock dies without releasing it, the next `acquire()` reclaims
the key once the expiry has passed — the schedule recovers on its own instead of
blocking until someone clears the row by hand.

```php
use EzPhp\Scheduler\Mutex\DatabaseMutexWithExpiry;

$mutex = new DatabaseMutexWithExpiry($pdo, 600); // TTL in seconds (default 3600)
$scheduler = new Scheduler($mutex);
```

- Uses its own `scheduler_locks_ttl` table, so it coexists with `DatabaseMutex`.
  (`CREATE TABLE IF NOT EXISTS` never adds a column to an existing table, so the
  expiry column could not be retrofitted onto `scheduler_locks` safely.)
- **Pick a TTL comfortably longer than the command's worst-case runtime.** A TTL
  shorter than the actual runtime lets another process reclaim the lock while the
  first is still working, which defeats overlap prevention entirely.
- Expired rows are reclaimed lazily, per key, on the next acquire. There is no
  background sweep — keys that stop being scheduled keep their last row.

### RedisMutex

For scheduled commands running on more than one host. `FileMutex` is bound to a
single filesystem and `DatabaseMutex` to a single database; Redis is usually
already shared across application servers.

```php
use EzPhp\Scheduler\Mutex\RedisMutex;

$redis = new Redis();
$redis->connect('127.0.0.1', 6379);

$mutex = new RedisMutex($redis, 600); // TTL in seconds (default 3600)
$scheduler = new Scheduler($mutex);
```

- Uses a single atomic `SET key value NX EX ttl`, so there is no read-then-write
  race between concurrent cron processes.
- Locks carry a TTL, so a crashed process does not block the schedule forever.
  **Pick a TTL longer than the command's worst-case runtime.**
- Keys are namespaced with `ez-php:scheduler:lock:` so they cannot collide with
  application data in a shared Redis database.
- **Fails closed**: if Redis is unreachable, `acquire()` returns false and the run
  is skipped, because the lock cannot be proven free. Running a scheduled job
  twice is the outcome this class exists to prevent.
- Requires `ext-redis`; the constructor throws `RuntimeException` without it.

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
| `cron(string $expression): self` | Due when the five-field cron expression matches |
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
