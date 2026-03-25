# Changelog

All notable changes to `ez-php/scheduler` are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

---

## [v1.0.1] — 2026-03-25

### Changed
- Tightened all `ez-php/*` dependency constraints from `"*"` to `"^1.0"` for predictable resolution

---

## [v1.0.0] — 2026-03-24

### Added
- `Scheduler` — cron-based job scheduler; evaluates all registered `ScheduleEntry` instances against the current time and runs due tasks
- `ScheduleEntry` — fluent task definition with cron expression, callback, description, and overlap-prevention settings
- `MutexInterface` — contract for overlap prevention; ensures a task does not run concurrently with itself
- `FileMutex` — filesystem-based mutex using lock files; works in any single-server setup
- `DatabaseMutex` — database-backed mutex using a `scheduler_mutex` table; suitable for multi-server deployments
- `SchedulerException` for invalid cron expressions, mutex acquisition failures, and task execution errors
