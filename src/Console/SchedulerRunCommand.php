<?php

declare(strict_types=1);

namespace EzPhp\Scheduler\Console;

use DateTimeImmutable;
use EzPhp\Console\CommandInterface;
use EzPhp\Console\Console;
use EzPhp\Console\Output;
use EzPhp\Contracts\ContainerInterface;
use EzPhp\Scheduler\Scheduler;
use EzPhp\Scheduler\SchedulerException;
use Throwable;

/**
 * Class SchedulerRunCommand
 *
 * `scheduler:run` — runs every due entry of this package's Scheduler (with its
 * withoutOverlapping() mutex and logger) through the application's Console.
 * Point system cron at it once per minute:
 *
 *   * * * * * php /var/www/html/ez scheduler:run
 *
 * Named `scheduler:run`, not `schedule:run`, because the framework already
 * ships `schedule:run` for its own, mutex-less Scheduler.
 *
 * The Console is resolved from the container when the command runs, not
 * injected: the framework builds user commands while constructing the Console
 * itself, so injecting it would be a circular dependency (the framework's own
 * ScheduleRunCommand defers it through a factory closure for the same reason).
 *
 * Opt-in: register with `$app->registerCommand(SchedulerRunCommand::class)` and
 * bind a configured Scheduler in a service provider.
 *
 * @package EzPhp\Scheduler\Console
 */
final class SchedulerRunCommand implements CommandInterface
{
    /**
     * SchedulerRunCommand Constructor
     *
     * @param Scheduler          $scheduler The application's configured Scheduler.
     * @param ContainerInterface $container Used to resolve the Console at run time.
     */
    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly ContainerInterface $container,
    ) {
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return 'scheduler:run';
    }

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return 'Run all due ez-php/scheduler entries (with overlap protection)';
    }

    /**
     * @return string
     */
    public function getHelp(): string
    {
        return "Usage: ez scheduler:run\n\n"
            . "Runs every ez-php/scheduler entry due at the current minute, honouring\n"
            . "withoutOverlapping(). Stops at the first entry that fails and exits 1.\n"
            . "Add to system cron to run every minute:\n\n"
            . '  * * * * * php /var/www/html/ez scheduler:run';
    }

    /**
     * @param list<string> $args Unused.
     *
     * @return int 0 when every due entry succeeded (or none was due), 1 otherwise.
     */
    public function handle(array $args): int
    {
        $now = new DateTimeImmutable();

        if ($this->scheduler->dueEntries($now) === []) {
            Output::line('No scheduled commands are due.');

            return 0;
        }

        $console = $this->container->make(Console::class);

        try {
            $this->scheduler->run($now, static function (string $command) use ($console): void {
                Output::info('Running: ' . $command);

                $argv = preg_split('/\s+/', trim($command), -1, PREG_SPLIT_NO_EMPTY);
                $exit = $console->run(['ez', ...($argv === false ? [] : $argv)]);

                if ($exit !== 0) {
                    throw new SchedulerException("Scheduled command '{$command}' exited with code {$exit}.");
                }
            });
        } catch (Throwable $e) {
            Output::error($e->getMessage());

            return 1;
        }

        return 0;
    }
}
