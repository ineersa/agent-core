<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Psr\Log\LoggerInterface;

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
 *
 * @internal
 */
final class StdoutRuntimeEventSink implements RuntimeEventSinkInterface
{
    /** @var resource|false|null */
    private static $stdout;

    /** @var bool|null */
    private static $isPipe;

    /**
     * @param LoggerInterface $logger used to log json_encode or write failures
     */
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function emit(RuntimeEvent $event): void
    {
        if (null === self::$isPipe) {
            self::$isPipe = \function_exists('posix_isatty') && !posix_isatty(\STDOUT);
        }

        if (!self::$isPipe) {
            return;
        }

        if (null === self::$stdout) {
            $handle = fopen('php://stdout', 'a');
            self::$stdout = false === $handle ? false : $handle;
        }

        if (false === self::$stdout) {
            return;
        }

        $encoded = json_encode($event->toArray(), \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $line = $encoded."\n";

        $written = @fwrite(self::$stdout, $line);
        if (false === $written || 0 === $written) {
            throw new \RuntimeException(\sprintf('StdoutRuntimeEventSink: fwrite to STDOUT pipe failed (event: %s). The controller process may be dead — aborting LLM consumer.', $event->type->value));
        }

        fflush(self::$stdout);
    }
}
