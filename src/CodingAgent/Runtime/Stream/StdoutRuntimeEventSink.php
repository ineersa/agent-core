<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * Writes transient runtime events to STDOUT as JSONL.
 *
 * Used in async (controller) mode: stream subscribers running inside the
 * LLM messenger:consume child process write deltas here. The controller
 * reads the child's stdout pipe and forwards the events to the TUI.
 *
 * Detection: STDOUT must be a pipe (not a TTY). In TUI/in-process mode the
 * terminal is a TTY and events must NOT be written to it — they're delivered
 * through the in-process sink instead. In the LLM consumer, STDOUT is a pipe
 * to the controller.
 */
final class StdoutRuntimeEventSink implements RuntimeEventSinkInterface
{
    /** @var resource|false|null */
    private static $stdout = null;

    /** @var bool|null */
    private static $isPipe = null;

    public function emit(RuntimeEvent $event): void
    {
        if (null === self::$isPipe) {
            self::$isPipe = !posix_isatty(\STDOUT);
        }

        if (!self::$isPipe) {
            return;
        }

        if (null === self::$stdout) {
            $handle = @fopen('php://stdout', 'ab');
            self::$stdout = false === $handle ? false : $handle;
        }

        if (false === self::$stdout) {
            return;
        }

        $line = json_encode($event->toArray(), \JSON_UNESCAPED_UNICODE) . "\n";
        @fwrite(self::$stdout, $line);
    }
}
