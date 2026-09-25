<?php

declare(strict_types=1);

namespace Tests\Support;

use EzPhp\Console\CommandInterface;

/**
 * Console command that records every invocation, for SchedulerRunCommand tests.
 *
 * Exits with 1 when called with the argument `--fail`.
 */
final class SchedulerProbeCommand implements CommandInterface
{
    /**
     * @var list<list<string>>
     */
    public static array $calls = [];

    public function getName(): string
    {
        return 'probe:record';
    }

    public function getDescription(): string
    {
        return 'Records its arguments (test double)';
    }

    public function getHelp(): string
    {
        return 'Usage: probe:record [args...]';
    }

    /**
     * @param list<string> $args
     */
    public function handle(array $args): int
    {
        self::$calls[] = $args;

        return in_array('--fail', $args, true) ? 1 : 0;
    }
}
