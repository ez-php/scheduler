<?php

declare(strict_types=1);

namespace Tests\Support;

use EzPhp\Contracts\ServiceProvider;
use EzPhp\Scheduler\Scheduler;

/**
 * Binds a Scheduler whose entries come from a static list the test sets before bootstrap.
 */
final class SchedulerProbeProvider extends ServiceProvider
{
    /**
     * Commands registered as everyMinute() entries, in order.
     *
     * @var list<string>
     */
    public static array $commands = [];

    public function register(): void
    {
        $this->app->bind(Scheduler::class, static function (): Scheduler {
            $scheduler = new Scheduler();

            foreach (self::$commands as $command) {
                $scheduler->command($command)->everyMinute();
            }

            return $scheduler;
        });
    }

    public function boot(): void
    {
    }
}
