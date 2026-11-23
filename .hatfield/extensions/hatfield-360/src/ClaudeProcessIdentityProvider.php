<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Hatfield360;

use Symfony\Component\Process\ExecutableFinder;

final class ClaudeProcessIdentityProvider
{
    /** @return array{pid: int, pidNamespace: string, startTicks: string}|null */
    public static function find(): ?array
    {
        if ('Linux' !== \PHP_OS_FAMILY || false === ($pid = getmypid()) || (string) $pid !== @readlink('/proc/self')) {
            return null;
        }
        $path = (new ExecutableFinder())->find('claude');
        $canonical = null === $path ? false : realpath($path);
        $executable = false === $canonical ? null : self::fileIdentity($canonical);
        if (null === $executable) {
            return null;
        }

        $chain = [];
        $owner = null;
        // Walk the whole visible chain, not a guessed hop. An unreadable exe
        // cannot identify an owner, but a readable stat still links the chain.
        // Reject a second positive match or an incomplete chain.
        for ($hop = 0; $hop < 64 && $pid > 0; ++$hop) {
            $row = self::process($pid);
            if (null === $row || isset($chain[$pid])) {
                return null;
            }
            $chain[$pid] = $row;
            if ($row['exe'] === $executable) {
                if (null !== $owner || '0' === $row['tty'] || null === $row['ns']) {
                    return null;
                }
                $owner = ['pid' => $pid, 'pidNamespace' => $row['ns'], 'startTicks' => $row['ticks']];
            }
            $pid = $row['parent'];
        }
        if (0 !== $pid || null === $owner || $owner['pid'] === getmypid() || $owner['pidNamespace'] !== ($chain[getmypid()]['ns'] ?? null) || self::fileIdentity($canonical) !== $executable) {
            return null;
        }
        foreach ($chain as $pid => $observed) {
            if (self::process($pid) !== $observed) {
                return null;
            }
        }

        return $owner;
    }

    /** @return array{int, int}|null */
    private static function fileIdentity(string $path): ?array
    {
        clearstatcache(true, $path);
        $stat = @stat($path);

        return false === $stat ? null : [$stat['dev'], $stat['ino']];
    }

    /** @return array{parent: int, tty: string, ticks: string, ns: ?string, exe: array{int, int}|null}|null */
    private static function process(int $pid): ?array
    {
        $base = '/proc/'.$pid;
        $stat = @file_get_contents($base.'/stat', length: 8192);
        $end = false === $stat ? false : strrpos($stat, ')');
        if (false === $stat || false === $end || !str_starts_with($stat, $pid.' (')) {
            return null;
        }
        // Fields after the comm start with state (field 3). The comm itself
        // may contain parentheses and spaces, so do not split it on spaces.
        $fields = explode(' ', substr($stat, $end + 2));
        $parent = $fields[1] ?? '';
        $tty = $fields[4] ?? '';
        $ticks = $fields[19] ?? '';
        if (1 !== preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/', $parent) || 1 !== preg_match('/\A-?[0-9]+\z/', $tty)
            || 1 !== preg_match('/\A[1-9][0-9]{0,19}\z/', $ticks) || \in_array($fields[0] ?? '', ['Z', 'X'], true)) {
            return null;
        }
        $ns = self::fileIdentity($base.'/ns/pid');

        return ['parent' => (int) $parent, 'tty' => $tty, 'ticks' => $ticks, 'ns' => null === $ns ? null : (string) $ns[1], 'exe' => self::fileIdentity($base.'/exe')];
    }
}
