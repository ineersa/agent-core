<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\BackgroundProcess;

/** Drains the command pipe without allowing retained stdout/stderr to grow without bound. */
final class BashLogWriter
{
    public const string FLAG = '--internal-bash-log-writer';
    public const int LIMIT = 100 * 1024 * 1024;
    public const int PREFIX = self::LIMIT - 65536 - 128;
    private const int TAIL = 65536;

    /**
     * @param resource $input
     * @param resource $output
     */
    public static function copy($input, $output): void
    {
        $prefix = stream_copy_to_stream($input, $output, self::PREFIX);
        if (false === $prefix) {
            throw new \RuntimeException('Unable to write Bash log prefix.');
        }

        $tail = '';
        $pending = '';
        $remaining = 0;
        while (!feof($input)) {
            $chunk = fread($input, min(8192, self::TAIL - \strlen($pending)));
            if (false === $chunk) {
                throw new \RuntimeException('Unable to drain Bash output.');
            }
            if ('' === $chunk) {
                continue;
            }
            $remaining += \strlen($chunk);
            $pending .= $chunk;
            if (self::TAIL === \strlen($pending)) {
                $tail = $pending;
                self::flushTail($output, $tail, $remaining);
                $pending = '';
            }
        }
        if ('' !== $pending) {
            self::flushTail($output, substr($tail.$pending, -self::TAIL), $remaining);
        }
    }

    /** @param resource $output */
    private static function flushTail($output, string $tail, int $remaining): void
    {
        $discarded = $remaining - \strlen($tail);
        $snapshot = $discarded > 0
            ? "\n".$tail."\n[Bash output retention limit: discarded {$discarded} bytes before the retained tail.]\n"
            : $tail;

        // Rewrite at most one tail per tail-sized increment, plus the final
        // partial increment. Keep bounded live inspection without a spool.
        if (0 !== fseek($output, self::PREFIX) || \strlen($snapshot) !== fwrite($output, $snapshot)) {
            throw new \RuntimeException('Unable to write Bash log tail.');
        }
    }
}
