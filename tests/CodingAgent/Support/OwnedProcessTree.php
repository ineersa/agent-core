<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

/** Read-only identities for a test-owned Linux process tree, captured before exit. */
final class OwnedProcessTree
{
    /** @return list<array{pid:int, start:string, cmdline:string, exe:string}> */
    public static function descendants(int $parent): array
    {
        $rows = [];
        $children = (string) @file_get_contents("/proc/{$parent}/task/{$parent}/children");
        foreach (explode(' ', trim($children)) as $token) {
            $pid = (int) $token;
            if ($pid <= 1 || 0 === posix_geteuid() || @fileowner("/proc/{$pid}") !== posix_geteuid()) {
                continue;
            }
            $stat = self::stat($pid);
            if (null === $stat) {
                continue;
            }
            $rows[] = [
                'pid' => $pid,
                'start' => $stat['start'],
                'cmdline' => str_replace("\0", ' ', (string) @file_get_contents("/proc/{$pid}/cmdline")),
                'exe' => (string) @readlink("/proc/{$pid}/exe"),
            ];
            $rows = [...$rows, ...self::descendants($pid)];
        }

        return $rows;
    }

    /** @param array{pid:int, start:string, cmdline:string, exe:string} $row */
    public static function stillAlive(array $row): bool
    {
        $stat = self::stat($row['pid']);

        return null !== $stat && $stat['start'] === $row['start'] && !\in_array($stat['state'], ['Z', 'X'], true);
    }

    /** @param array{pid:int, start:string, cmdline:string, exe:string} $row */
    public static function canSignal(array $row): bool
    {
        $pid = $row['pid'];
        $env = @file_get_contents("/proc/{$pid}/environ");

        return 0 !== posix_geteuid()
            && @fileowner("/proc/{$pid}") === posix_geteuid()
            && self::stillAlive($row)
            && @readlink("/proc/{$pid}/exe") === $row['exe']
            && false !== $env
            && !preg_match('/(?:^|\x00)HATFIELD_SESSION_ID=/', $env);
    }

    /** @return array{state:string, start:string}|null */
    private static function stat(int $pid): ?array
    {
        $raw = @file_get_contents("/proc/{$pid}/stat");
        if (false === $raw || false === ($end = strrpos($raw, ')'))) {
            return null;
        }
        $fields = explode(' ', trim(substr($raw, $end + 1)));
        if (!isset($fields[19])) {
            return null;
        }

        return ['state' => $fields[0], 'start' => $fields[19]];
    }
}
