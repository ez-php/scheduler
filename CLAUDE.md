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
1. `sync_guidelines.php --check` — fails if any `CLAUDE.md` has drifted from this file
2. `check_test_classes.php` — fails on a duplicate test class name (all packages share the `Tests\` namespace, so a collision is a fatal error in the aggregated run, not a test failure)
3. `phpstan analyse` — static analysis, level 9, config: `phpstan.neon`
4. `php-cs-fixer fix` — auto-fixes style (`@PSR12` + `@PHP83Migration` + strict rules)
   *(Note: `@PHP85Migration` does not exist yet in php-cs-fixer; `@PHP83Migration` is the highest available and is used intentionally even though the project targets PHP 8.5)*
5. `phpunit` — all tests with coverage

Individual commands when needed:
```
composer analyse             # PHPStan only
composer cs                  # CS Fixer only
composer test                # PHPUnit only
composer guidelines:check    # CLAUDE.md drift only
composer test-classes:check  # duplicate test class names only
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
- Concrete classes are `final` — extend behavior through composition, not inheritance. Exception-hierarchy base classes (e.g. `EzPhpException`, `HttpException`, `CacheException`) are one carve-out, since they exist specifically to be extended. A documented template-method-style base class (e.g. `Mailable`, meant to be configured via constructor-time subclassing) is the other — the owning module's `CLAUDE.md` must record it under Design Decisions.

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

**Do not edit part 1 by hand.** It is generated from `CODING_GUIDELINES.md` by
`sync_guidelines.php` at the project root:

```
php sync_guidelines.php            # rewrite every out-of-sync CLAUDE.md
php sync_guidelines.php --check    # report drift, exit 1 if any (CI / pre-commit)
```

Edit `CODING_GUIDELINES.md`, then run the script — it replaces everything before the
`# Package:` / `# Directory:` / `# Project:` heading and preserves the hand-written
section below it byte-for-byte. Editing a single copy only creates drift; before this
script existed, all 40 copies had diverged.

### 3 — Scaffolding a new module

`make_module.php` at the project root writes the required-file set and the monorepo
wiring in one step, wrapping `docker-init` for the Docker subset:

```
composer module:make <name> -- --description="..."
php make_module.php <name> --description="..." --services=mysql,redis
```

`<name>` is the kebab-case package name; the namespace is derived as
`EzPhp\<PascalCase>` unless `--namespace=` overrides it (`bignum` → `BigNum`,
`opcache` → `OPCache`, and `dotenv` → `Env` are existing exceptions the guess
gets wrong).

To bring in a module whose code already lives in its own repository instead of
generating a fresh skeleton, pass `--repo=` with a git URL:

```
php make_module.php <name> --repo=<git-url> [--namespace=Foo]
```

This runs `git submodule add <url> modules/<name>` instead of writing package
files, then applies the same monorepo wiring below. It is mutually exclusive
with `--services` and `--description` — a submodule brings its own Docker
scaffold (if any) and its own `composer.json` description. A minimal `CLAUDE.md`
stub is written only if the submodule doesn't already ship one, so
`composer guidelines:sync` has a `# Package:` heading to anchor part 1 against.

It writes `modules/<name>/` and registers the module in the four places the monorepo
needs it — root `composer.json` (`autoload.psr-4`), `phpstan.neon`, `phpunit.xml`
(test suite **and** coverage source), and `packages.sh` (alphabetical position).

Two things stay manual on purpose:

- **`CLAUDE.md` part 1** — only the `# Package:` section is generated. Run
  `composer guidelines:sync` afterwards; baking a guidelines copy into the generator
  would recreate the drift the sync script exists to prevent.
- **The host-port table below** (`--services` only) — editing it marks all ~40
  `CLAUDE.md` copies as drifted at once, so the next `composer full` would fail for
  a brand-new module. The generator prints which ports to claim instead.

### 4 — Docker scaffold

Run from the new module root (requires `"ez-php/docker": "^2.0"` in `require-dev`):

```
vendor/bin/docker-init
```

This copies `Dockerfile`, `docker-compose.yml`, `.env.example`, `start.sh`, and `docker/` into the module, replacing `{{MODULE_NAME}}` placeholders. Existing files are never overwritten.

Pass `--services` to merge MySQL/Redis/Meilisearch service definitions directly into `docker-compose.yml` and uncomment the matching sections in `.env.example`, instead of adapting them by hand afterward:

```
vendor/bin/docker-init --services=mysql
vendor/bin/docker-init --services=redis
vendor/bin/docker-init --services=meilisearch
vendor/bin/docker-init --services=mysql,redis
```

After scaffolding:

1. Adapt `docker-compose.yml` — add or remove services (MySQL, Redis, Meilisearch) as needed
2. Adapt `.env.example` — fill in connection defaults matching the services above
3. Assign a unique host port for each exposed service (see table below)

**Allocated host ports:**

| Package | `DB_HOST_PORT` (MySQL) | Redis host port | `MEILISEARCH_PORT` |
|---|---|---|---|
| root (`ez-php-project`) | 3306 | 6379 (`REDIS_PORT`) | 7700 |
| `ez-php/framework` | 3307 | — | — |
| `ez-php/orm` | 3309 | — | — |
| `ez-php/cache` | — | 6380 (`REDIS_HOST_PORT`) | — |
| `ez-php/queue` | 3310 | 6381 (`REDIS_HOST_PORT`) | — |
| `ez-php/rate-limiter` | — | 6382 (`REDIS_HOST_PORT`) | — |
| `ez-php/search` | — | — | 7701 |
| **next free** | **3311** | **6383** | **7702** |

Only set a port for services the module actually uses. Modules without external services need no port config.

> The `MEILISEARCH_PORT` column is the **host** port. Inside a Compose network the service is always reachable at `http://meilisearch:7700` regardless of the host mapping — only publish-side ports need to be unique.

> The "Redis host port" column is likewise the **host**-published port. `ez-php/cache`, `ez-php/queue`, and `ez-php/rate-limiter` map it through a separate `REDIS_HOST_PORT` env var in `docker-compose.yml`, keeping `REDIS_PORT` fixed at `6379` for in-container connections (the app container always reaches Redis at `redis:6379` over the Compose network, regardless of the host mapping) — the root project is the one exception, since it has no host/container split and uses `REDIS_PORT` for both.

> This table tracks only MySQL, Redis, and Meilisearch ports — the three services shared across multiple modules where a collision is otherwise easy to introduce. `ez-php/mail`'s Mailpit service is the one other module with published host ports: SMTP `1025` and web UI `8025`, mapped through `MAILPIT_SMTP_HOST_PORT`/`MAILPIT_API_HOST_PORT` in `modules/mail/docker-compose.yml` (mirroring the `*_HOST_PORT` pattern above), documented in `modules/mail/.env.example`. It isn't a table column because no other module runs Mailpit, so there is nothing to collide with — but a new module adding its own single-use service's ports should likewise parameterize them and document the defaults in its own `.env.example` rather than adding a column here.

### 5 — Monorepo scripts

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
    ├── FileMutex.php               — flock()-based mutex; lock files stored in a configurable directory
    ├── DatabaseMutex.php           — PDO INSERT/DELETE-based mutex; auto-creates scheduler_locks table
    ├── DatabaseMutexWithExpiry.php — as above plus a TTL; reclaims expired locks; auto-creates scheduler_locks_ttl
    └── RedisMutex.php              — atomic SET NX EX lock; multi-host; requires ext-redis

tests/
├── TestCase.php                — base PHPUnit test case
├── ScheduleEntryTest.php       — covers ScheduleEntry: all frequency methods, withoutOverlapping, mutex key
├── SchedulerTest.php           — covers Scheduler: registration, dueEntries, run, mutex acquire/release/skip
└── Mutex/
    ├── FileMutexTest.php       — covers FileMutex: acquire, release, double-lock, directory creation
    ├── DatabaseMutexTest.php   — covers DatabaseMutex: acquire, release, duplicate key, table creation (SQLite)
    ├── DatabaseMutexWithExpiryTest.php — covers TTL reclaim, key isolation, table separation (SQLite)
    └── RedisMutexTest.php       — covers acquire/release, cross-instance locking, key prefix, TTL (live Redis; skipped if absent)
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
- **`DatabaseMutex` has no expiry/TTL; `DatabaseMutexWithExpiry` does** — `DatabaseMutex` keeps a lock until it is explicitly released, so a crashed process leaves a stale row that blocks that entry until cleared by hand. `DatabaseMutexWithExpiry` stores an `expires_at` timestamp and reclaims the key on the next acquire once it has passed. `DatabaseMutex` is left unchanged rather than extended.
- **`DatabaseMutexWithExpiry` uses its own `scheduler_locks_ttl` table** — This is a compatibility constraint, not a style choice. Both classes create their table with `CREATE TABLE IF NOT EXISTS`, which is a no-op against an existing table and therefore never adds a column. Adding `expires_at` to `DatabaseMutex`'s `scheduler_locks` would ship code that queries a column absent on every already-deployed install, raising a `PDOException` at runtime. Separate tables let both mutexes coexist and make the choice explicit at construction.
- **Reclaim and insert are not one atomic statement** — `acquire()` deletes an expired row for the key, then inserts. The primary key on `lock_key` provides mutual exclusion: if two processes both delete the same expired row, only one insert can succeed and the other returns false. A single atomic upsert would need dialect-specific syntax (`ON DUPLICATE KEY UPDATE` vs `ON CONFLICT`), which the two-statement form avoids.
- **TTL is a safety net, not a runtime limit** — A TTL shorter than the command's actual runtime lets a second process reclaim the lock while the first is still working, defeating overlap prevention. The default is one hour (`DEFAULT_TTL_SECONDS`).
- **`RedisMutex` uses `SET … NX EX`, one atomic command** — The set-if-absent test and the write are a single Redis operation, so there is no read-then-write race between cron processes. It is the driver for multi-host deployments: `FileMutex` is bound to one filesystem, `DatabaseMutex` to one database.
- **`RedisMutex` fails *closed* on a Redis outage, unlike `ez-php/rate-limiter`'s `RedisDriver` which fails open** — The failure modes are not symmetric. An un-throttled request is a minor problem; running a scheduled job twice concurrently is precisely what the mutex exists to prevent. When Redis is unreachable the lock cannot be proven free, so `acquire()` returns false and the run is skipped.
- **`RedisMutex` keys are namespaced with `KEY_PREFIX`** — Scheduler locks usually share a Redis database with application data; the prefix prevents collisions. The constant is public so tests can assert on the stored key.
- **`ext-redis` is not declared in `composer.json`** — Matches `ez-php/rate-limiter`, which also ships a `RedisDriver` without declaring the extension. Availability is checked at construction with `extension_loaded()` and raises `RuntimeException`, keeping the package installable without Redis.
- **Expiry stored as a Unix timestamp `INTEGER`** — Avoids `DATETIME` dialect differences between MySQL and SQLite and keeps the comparison a plain integer compare.
- **`ScheduleEntry` is mutable** — Frequency and overlap flags are set after construction via fluent methods (the caller receives the entry from `Scheduler::command()`). Immutability would require a builder pattern for no real benefit.
- **No `schedule:run` command in this package** — The framework already provides `ScheduleRunCommand`. Integrating with this module requires replacing `Scheduler` in the service provider and passing a suitable executor — documented in the README.

---

## Testing Approach

- **No external infrastructure** — All tests run in-process. `DatabaseMutexTest` and `DatabaseMutexWithExpiryTest` use an in-memory SQLite PDO. `FileMutexTest` uses temp directories cleaned in `tearDown()`.
- **`RedisMutexTest` needs a live Redis** — Available via the root Docker Compose stack (`ez-php-redis`); it reads `REDIS_HOST`/`REDIS_PORT` and `markTestSkipped()`s when `ext-redis` is missing or the server is unreachable, so the module still tests green standalone. It uses Redis database **3**, since `ez-php/rate-limiter`'s tests use database 2. No `REDIS_PORT` was allocated to this module in the host-port table — the tests reuse the root stack rather than adding a scheduler-specific Redis service.
- **Expiry is tested via TTL, not `sleep()`** — `DatabaseMutexWithExpiryTest` constructs the mutex with a TTL of `0` (or negative) so the lock is already reclaimable the moment it is written. This exercises the crash-recovery path with no clock abstraction and no slow test.
- **`FileMutexTest::testAcquireReturnsFalseWhenAlreadyLocked`** — Two `FileMutex` instances on the same `lockDir` and same key simulate two concurrent processes. PHP's `flock(LOCK_EX|LOCK_NB)` on a second file handle to the same path correctly returns false when the first holds the lock.
- **Anonymous class stubs** — `SchedulerTest` uses anonymous classes implementing `MutexInterface` instead of a mock framework. The `released` keys are exposed as public properties on the anonymous class (not by-reference constructor args) so PHPStan can verify reads.
- **Uncovered lines** — Lines 41 and 60 of `FileMutex` (`mkdir()` failure and `fopen()` failure) are defensive OS-error guards untestable without filesystem mocking.

---

## What Does NOT Belong Here

| Concern | Where it belongs |
|---------|-----------------|
| `schedule:run` console command | `ez-php/framework` `ScheduleRunCommand` (already exists) |
| Periodic/background purge of expired lock rows | Application layer — `DatabaseMutexWithExpiry` reclaims lazily per key on acquire; a global sweep of dead keys is a maintenance job |
| Lock ownership tokens (release-only-if-still-mine) | Not implemented — `RedisMutex::release()` deletes the key unconditionally; a run that overruns its TTL could delete a lock another process has since taken |
| Cron expression parsing (`* * * * *` syntax) | Out of scope — use predefined frequency methods |
| Distributed locking beyond single-DB or single-FS scope | Application-level concern |
| Job queuing / background processing | `ez-php/queue` |
