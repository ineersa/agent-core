<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

/** Early entry point: detach without booting the application kernel. */
final class DetachedProcessLauncher
{
    public const string FLAG = '--internal-detached-process';

    /** @param list<string> $argv */
    public static function run(array $argv): never
    {
        if (4 !== \count($argv)) {
            echo "Invalid detached launcher arguments\n";
            exit(1);
        }
        $pid = pcntl_fork();
        if ($pid < 0) {
            echo "pcntl_fork failed\n";
            exit(1);
        }
        if ($pid > 0) {
            exit(0);
        }
        if (posix_setsid() < 0) {
            echo "posix_setsid failed\n";
            exit(1);
        }
        @pcntl_exec($argv[2], ['-c', $argv[3]]);
        echo "pcntl_exec failed\n";
        exit(1);
    }
}
