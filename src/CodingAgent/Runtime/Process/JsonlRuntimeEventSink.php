<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Process;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;

/**
 * JSONL sink that writes transient runtime events directly to an output stream.
 *
 * Used in headless (process-transport) mode. Each emitted event is
 * immediately encoded as a JSONL line and flushed to the output.
 * The parent process (JsonlProcessAgentSessionClient) reads these
 * lines from the subprocess stdout.
 *
 * Design note: for the initial implementation, headless-mode events
 * are delivered after the LLM invocation completes (the headless
 * command polls events() in a synchronous loop). True real-time
 * headless streaming would require the observer to write JSONL
 * directly during platform invocation, which is a future enhancement.
 */
final class JsonlRuntimeEventSink implements RuntimeEventSinkInterface
{
    /** @var resource */
    private $output;

    /**
     * @param resource $output Open writable stream (e.g., fopen('php://stdout', 'w'))
     */
    public function __construct($output)
    {
        if (!\is_resource($output)) {
            throw new \InvalidArgumentException('JsonlRuntimeEventSink requires an open writable stream resource.');
        }

        $this->output = $output;
    }

    public function emit(RuntimeEvent $event): void
    {
        fwrite($this->output, JsonlCodec::encodeEvent($event));
        fflush($this->output);
    }
}
