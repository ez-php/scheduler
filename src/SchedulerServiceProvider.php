<?php

declare(strict_types=1);

namespace EzPhp\Scheduler;

use EzPhp\Cache\CacheInterface;
use EzPhp\Contracts\ConfigInterface;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Contracts\DatabaseInterface;
use EzPhp\Contracts\ServiceProvider;
use EzPhp\Scheduler\Console\SchedulerListCommand;
use EzPhp\Scheduler\Console\SchedulerRunCommand;
use EzPhp\Scheduler\Mutex\CacheMutex;
use EzPhp\Scheduler\Mutex\DatabaseMutex;
use EzPhp\Scheduler\Mutex\DatabaseMutexWithExpiry;
use EzPhp\Scheduler\Mutex\FileMutex;
use EzPhp\Scheduler\Mutex\RedisMutex;
use Redis;
use RedisException;

/**
 * Class SchedulerServiceProvider
 *
 * Binds one shared Scheduler whose mutex is chosen by `scheduler.mutex`
 * (file, database, database_expiry, redis, cache or none; default file) and
 * registers `scheduler:run` and `scheduler:list`.
 *
 * The Scheduler (and its mutex) is constructed whenever the console is built,
 * because the commands depend on it: `database`/`database_expiry` therefore run
 * their `CREATE TABLE IF NOT EXISTS` on each CLI call, and an unreachable Redis is
 * tolerated (the mutex then fails closed) rather than breaking every command.
 *
 * Entries stay application code: a provider registered after this one adds them
 * in its boot(), e.g. `$app->make(Scheduler::class)->command('reports:send')->daily();`
 * — the container caches the Scheduler, so every caller sees the same entries.
 *
 * @package EzPhp\Scheduler
 */
final class SchedulerServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
        $this->app->bind(Scheduler::class, function (ContainerInterface $app): Scheduler {
            return new Scheduler($this->makeMutex($app->make(ConfigInterface::class)));
        });
    }

    /**
     * @return void
     */
    public function boot(): void
    {
        $this->registerCommand(SchedulerRunCommand::class);
        $this->registerCommand(SchedulerListCommand::class);
    }

    /**
     * @param ConfigInterface $config
     *
     * @throws SchedulerException On an unknown `scheduler.mutex` value.
     *
     * @return MutexInterface|null
     */
    private function makeMutex(ConfigInterface $config): ?MutexInterface
    {
        $driver = $config->get('scheduler.mutex', 'file');
        $ttl = $config->get('scheduler.ttl', RedisMutex::DEFAULT_TTL_SECONDS);
        $ttl = is_int($ttl) ? $ttl : RedisMutex::DEFAULT_TTL_SECONDS;

        return match ($driver) {
            'file' => $this->makeFileMutex($config),
            'database' => new DatabaseMutex($this->app->make(DatabaseInterface::class)->getPdo()),
            'database_expiry' => new DatabaseMutexWithExpiry($this->app->make(DatabaseInterface::class)->getPdo(), $ttl),
            'redis' => new RedisMutex($this->makeRedis($config), $ttl),
            'cache' => new CacheMutex($this->app->make(CacheInterface::class), $ttl),
            'none', null => null,
            default => throw new SchedulerException(
                'Unknown scheduler.mutex "' . (is_scalar($driver) ? (string) $driver : get_debug_type($driver))
                . '"; expected file, database, database_expiry, redis, cache or none.',
            ),
        };
    }

    /**
     * @param ConfigInterface $config
     *
     * @return FileMutex
     */
    private function makeFileMutex(ConfigInterface $config): FileMutex
    {
        $path = $config->get('scheduler.file.path');

        return new FileMutex(is_string($path) && $path !== '' ? $path : sys_get_temp_dir() . '/ez-scheduler-locks');
    }

    /**
     * The Scheduler is built whenever the console is (its commands need it), so an
     * unreachable Redis must not break unrelated `php ez …` commands: a failed connect
     * is swallowed and RedisMutex fails closed instead (acquire() false, isLocked() true).
     *
     * @param ConfigInterface $config
     *
     * @return Redis
     */
    private function makeRedis(ConfigInterface $config): Redis
    {
        $host = $config->get('scheduler.redis.host', '127.0.0.1');
        $port = $config->get('scheduler.redis.port', 6379);
        $database = $config->get('scheduler.redis.database', 0);

        $redis = new Redis();

        try {
            $redis->connect(is_string($host) ? $host : '127.0.0.1', is_int($port) ? $port : 6379, 1.0);
            $redis->select(is_int($database) ? $database : 0);
        } catch (RedisException) {
            // Left unconnected on purpose; see above.
        }

        return $redis;
    }
}
