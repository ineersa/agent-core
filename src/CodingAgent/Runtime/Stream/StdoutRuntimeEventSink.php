<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Stream;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeTransportException;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
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
 *
 * Single owner of stdout pipe detection, the lazy php://stdout handle, and
 * {@see JsonlCodec::encodeEvent()} encoding; {@see CommittedRuntimeEventStdoutSink}
 * delegates here so transient and committed JSONL never drift.
 *
 * @internal
 */
final class StdoutRuntimeEventSink implements RuntimeEventSinkInterface
{
    /** @var resource|false|null */
    private static $stdout;

    /** @var bool|null */
    private static $isPipe;

    public function emit(RuntimeEvent $event): void
    {
        if (false === $this->write($event)) {
            throw new RuntimeTransportException(\sprintf('StdoutRuntimeEventSink: fwrite to STDOUT pipe failed (event: %s). The controller process may be dead — aborting LLM consumer.', $event->type));
        }
    }

    /**
     * Whether this process writes runtime events to a controller pipe.
     */
    public function isPipe(): bool
    {
        if (null === self::$isPipe) {
            self::$isPipe = \function_exists('posix_isatty') && !posix_isatty(\STDOUT);
        }

        return self::$isPipe;
    }

    /**
     * Writes one event to STDOUT when it is a pipe, encoded via {@see JsonlCodec::encodeEvent()}.
     *
     * @return bool|null null when STDOUT is not a pipe or the handle could not be opened; false when a write failed or wrote zero bytes; true when written and flushed
     */
    public function write(RuntimeEvent $event): ?bool
    {
        if (!$this->isPipe()) {
            return null;
        }

        if (null === self::$stdout) {
            $handle = fopen('php://stdout', 'ab');
            self::$stdout = false === $handle ? false : $handle;
        }

        if (false === self::$stdout) {
            return null;
        }

        $line = JsonlCodec::encodeEvent($event);
        if (!JsonlCodec::write(self::$stdout, $line)) {
            return false;
        }

        fflush(self::$stdout);

        return true;
    }
}
