# Coding Guidelines

Applies to the entire ez-php project — framework core, all modules, and the application template.

---

## Environment

- PHP **8.5**, Composer for dependency management
- All project based commands run **inside Docker** — never directly on the host

```
docker compose exec app <command>
```

Container name: `ez-php-app`, service name: `app`.

---

## Quality Suite

Run after every change:

```
docker compose exec app composer full
```

Executes in order:
1. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
2. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
3. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse   # PHPStan only
composer cs        # CS Fixer only
composer test      # PHPUnit only
```

**PHPStan:** never suppress with `@phpstan-ignore-line` — always fix the root cause.

---

## Coding Standards

- `declare(strict_types=1)` at the top of every PHP file
- Typed properties, parameters, and return values — avoid `mixed`
- PHPDoc on every class and public method
- One responsibility per class — keep classes small and focused
- Constructor injection — no service locator pattern
- No global state unless intentional and documented

**Naming:**

| Thing | Convention |
|---|---|
| Classes / Interfaces | `PascalCase` |
| Methods / variables | `camelCase` |
| Constants | `UPPER_CASE` |
| Files | Match class name exactly |

**Principles:** SOLID · KISS · DRY · YAGNI

---

## Workflow & Behavior

- Write tests **before or alongside** production code (test-first)
- Read and understand the relevant code before making any changes
- Modify the minimal number of files necessary
- Keep implementations small — if it feels big, it likely belongs in a separate module
- No hidden magic — everything must be explicit and traceable
- No large abstractions without clear necessity
- No heavy dependencies — check if PHP stdlib suffices first
- Respect module boundaries — don't reach across packages
- Keep the framework core small — what belongs in a module stays there
- Document architectural reasoning for non-obvious design decisions
- Do not change public APIs unless necessary
- Prefer composition over inheritance — no premature abstractions

---

## New Modules & CLAUDE.md Files

### 1 — Required files

Every module under `modules/<name>/` must have:

| File | Purpose |
|---|---|
| `composer.json` | package definition, deps, autoload |
| `phpstan.neon` | static analysis config, level 9 |
| `phpunit.xml` | test suite config |
| `.php-cs-fixer.php` | code style config |
| `.gitignore` | ignore `vendor/`, `.env`, cache |
| `.env.example` | environment variable defaults (copy to `.env` on first run) |
| `docker-compose.yml` | Docker Compose service definition (always `container_name: ez-php-<name>-app`) |
| `docker/app/Dockerfile` | module Docker image (`FROM au9500/php:8.5`) |
| `docker/app/container-start.sh` | container entrypoint: `composer install` → `sleep infinity` |
| `docker/app/php.ini` | PHP ini overrides (`memory_limit`, `display_errors`, `xdebug.mode`) |
| `.github/workflows/ci.yml` | standalone CI pipeline |
| `README.md` | public documentation |
| `tests/TestCase.php` | base test case for the module |
| `start.sh` | convenience script: copy `.env`, bring up Docker, wait for services, exec shell |
| `CLAUDE.md` | see section 2 below |

### 2 — CLAUDE.md structure

Every module `CLAUDE.md` must follow this exact structure:

1. **Full content of `CODING_GUIDELINES.md`, verbatim** — copy it as-is, do not summarize or shorten
2. A `---` separator
3. `# Package: ez-php/<name>` (or `# Directory: <name>` for non-package directories)
4. Module-specific section covering:
   - Source structure — file tree with one-line description per file
   - Key classes and their responsibilities
   - Design decisions and constraints
   - Testing approach and infrastructure requirements (MySQL, Redis, etc.)
   - What does **not** belong in this module

### 3 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^1.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=mysql,redis
```

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | `REDIS_PORT` |
|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 |
| `ez-php/framework` | 3307 | — |
| `ez-php/orm` | 3309 | — |
| `ez-php/cache` | — | 6380 |
| `ez-php/queue` | 3310 | 6381 |
| `ez-php/rate-limiter` | — | 6382 |
| **next free** | **3311** | **6383** |

Only set a port for services the module actually uses. Modules without external services need no port config.

### 4 — Monorepo scripts

`packages.sh` at the project root is the **central package registry**. Both `push_all.sh` and `update_all.sh` source it — the package list lives in exactly one place.

When adding a new module, add `"$ROOT/modules/<name>"` to the `PACKAGES` array in `packages.sh` in **alphabetical order** among the other `modules/*` entries (before `framework`, `ez-php`, and the root entry at the end).

---

# Package: ez-php/scheduler

Cron-based job scheduler for ez-php applications — frequency-based entry registration, mutex-backed overlap prevention, and a pluggable driver architecture (File, Database).

---

## Source Structure

```
src/
├── SchedulerException.php      — base exception for all scheduler errors
├── MutexInterface.php          — contract: acquire(key): bool, release(key): void
├── ScheduleEntry.php           — fluent builder: frequency methods + withoutOverlapping()
├── Scheduler.php               — registry + dueEntries() + run(callable $executor)
└── Mutex/
    ├── FileMutex.php           — flock()-based mutex; lock files stored in a configurable directory
    └── DatabaseMutex.php       — PDO INSERT/DELETE-based mutex; auto-creates scheduler_locks table

tests/
├── TestCase.php                — base PHPUnit test case
├── ScheduleEntryTest.php       — covers ScheduleEntry: all frequency methods, withoutOverlapping, mutex key
├── SchedulerTest.php           — covers Scheduler: registration, dueEntries, run, mutex acquire/release/skip
└── Mutex/
    ├── FileMutexTest.php       — covers FileMutex: acquire, release, double-lock, directory creation
    └── DatabaseMutexTest.php   — covers DatabaseMutex: acquire, release, duplicate key, table creation (SQLite)
```

---

## Key Classes and Responsibilities

### ScheduleEntry (`src/ScheduleEntry.php`)

Fluent builder for a single scheduled job. Holds the command name, a due-predicate closure, and an overlap flag.

| Method | Description |
|--------|-------------|
| `everyMinute()` | Due every cron invocation (always true) |
| `everyFiveMinutes()` | Due when `minute % 5 === 0` |
| `hourly()` | Due at minute :00 |
| `daily()` | Due at 00:00 |
| `weekly()` | Due on Sunday at 00:00 |
| `monthly()` | Due on the 1st at 00:00 |
| `withoutOverlapping(bool $enabled = true)` | Enables mutex-based skip when already running |
| `isDue(DateTimeInterface $time)` | Evaluates the predicate against the given time |
| `getMutexKey()` | Returns a stable `sha1`-derived key for the mutex |

---

### Scheduler (`src/Scheduler.php`)

Registry and runner. Accepts an optional `MutexInterface`. The `run()` method iterates due entries and calls the provided executor callable — in an ez-php application, the executor would call `Console::call($commandName)`.

Mutex flow in `run()`:
1. If `shouldSkipIfOverlapping()` and no mutex → throw `SchedulerException`
2. If `shouldSkipIfOverlapping()` and mutex → `acquire(key)`: skip on false, wrap executor in try/finally to release
3. Otherwise → call executor directly

---

### FileMutex (`src/Mutex/FileMutex.php`)

Uses PHP's `flock(LOCK_EX|LOCK_NB)` on per-key `.lock` files in a configurable directory. Non-blocking: returns false immediately if the file is already exclusively locked by another process. The lock directory is created automatically on construction.

Lock files are never deleted — their inodes remain stable across processes and cron invocations.

---

### DatabaseMutex (`src/Mutex/DatabaseMutex.php`)

Uses a `scheduler_locks` table (created via `CREATE TABLE IF NOT EXISTS` on construction). Acquiring inserts a row; a duplicate-key `PDOException` signals the lock is already held. Releasing deletes the row. Compatible with MySQL and SQLite.

---

## Design Decisions and Constraints

- **No framework dependency** — `ez-php/scheduler` requires only `php: ^8.5`. It accepts a plain `PDO` instance for `DatabaseMutex`; no `ez-php/framework` import is needed. The executor callable passed to `run()` decouples the scheduler from `ez-php/console`.
- **Callable executor in `run()`** — Rather than injecting a `Console` instance, `run()` accepts `callable(string): void`. This keeps the scheduler standalone and testable with a simple closure.
- **`MutexInterface` throws `SchedulerException` on misconfiguration, not on lock fail** — A missing mutex when `withoutOverlapping()` is requested is a programmer error (fail-fast). A failed lock acquire is a normal runtime event (silent skip).
- **`FileMutex` uses `flock()` not `sem_get()`** — `flock()` is universally available without the `sysvsem` extension. The lock is tied to the file handle, so the process dying automatically releases it (no stale lock cleanup needed).
- **`DatabaseMutex` has no expiry/TTL** — Stale locks (from crashed processes) must be cleaned manually. A TTL column with periodic cleanup was considered but deferred (YAGNI) — add a `DatabaseMutexWithExpiry` when needed.
- **`ScheduleEntry` is mutable** — Frequency and overlap flags are set after construction via fluent methods (the caller receives the entry from `Scheduler::command()`). Immutability would require a builder pattern for no real benefit.
- **No `schedule:run` command in this package** — The framework already provides `ScheduleRunCommand`. Integrating with this module requires replacing `Scheduler` in the service provider and passing a suitable executor — documented in the README.

---

## Testing Approach

- **No external infrastructure** — All tests run in-process. `DatabaseMutexTest` uses an in-memory SQLite PDO. `FileMutexTest` uses temp directories cleaned in `tearDown()`.
- **`FileMutexTest::testAcquireReturnsFalseWhenAlreadyLocked`** — Two `FileMutex` instances on the same `lockDir` and same key simulate two concurrent processes. PHP's `flock(LOCK_EX|LOCK_NB)` on a second file handle to the same path correctly returns false when the first holds the lock.
- **Anonymous class stubs** — `SchedulerTest` uses anonymous classes implementing `MutexInterface` instead of a mock framework. The `released` keys are exposed as public properties on the anonymous class (not by-reference constructor args) so PHPStan can verify reads.
- **Uncovered lines** — Lines 41 and 60 of `FileMutex` (`mkdir()` failure and `fopen()` failure) are defensive OS-error guards untestable without filesystem mocking.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---------|-----------------|
| `schedule:run` console command | `ez-php/framework` `ScheduleRunCommand` (already exists) |
| Mutex with automatic TTL/expiry cleanup | A future `DatabaseMutexWithExpiry` or separate concern |
| Redis-based mutex | A future `RedisMutex` driver if/when needed |
| Cron expression parsing (`* * * * *` syntax) | Out of scope — use predefined frequency methods |
| Distributed locking beyond single-DB or single-FS scope | Application-level concern |
| Job queuing / background processing | `ez-php/queue` |
