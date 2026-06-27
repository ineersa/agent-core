<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * Polls the LLM consumer child process stdout for transient streaming deltas.
 *
 * Stream subscribers inside the LLM consumer write JSONL to STDOUT. This
 * poller reads incremental output, accumulates partial lines across poll
 * cycles, parses valid RuntimeEvent JSONL, and delegates to RuntimeEventEmitter.
 *
 * Non-JSONL lines (e.g. messenger:consume output) are treated as expected
 * protocol noise and logged at debug level. Consecutive unparseable lines
 * trigger a ProtocolError after a configurable threshold.
 *
 * @see HeadlessController
 * @see RuntimeEventEmitter
 */
final class LlmStdoutPoller
{
    private const int MAX_CONSECUTIVE_BAD_LLM_LINES = 10;

    /** Partial-line buffer for LLM consumer stdout reads. */
    private string $llmStdoutBuffer = '';

    /** Consecutive unparseable LLM stdout lines for error threshold. */
    private int $consecutiveBadLlmLines = 0;

    public function __construct(
        private readonly ConsumerSupervisor $consumerSupervisor,
        private readonly RuntimeEventEmitter $emitter,
        private readonly RuntimeExceptionBoundary $boundary,
        private readonly LoggerInterface $logger,
        private readonly int $maxBadLines = self::MAX_CONSECUTIVE_BAD_LLM_LINES,
    ) {
    }

    /**
     * Register the LLM stdout poll loop.
     *
     * Polls the LLM consumer child process stdout pipe at the given
     * interval, parses complete JSONL lines, and delegates valid
     * RuntimeEvents to the emitter.
     */
    public function startPollLoop(float $interval = 0.01): void
    {
        EventLoop::repeat($interval, function (): void {
            if ($this->emitter->isShuttingDown()) {
                return;
            }

            $this->pollLlmStdout();
        });
    }

    private function pollLlmStdout(): void
    {
        $llmProcess = $this->consumerSupervisor->getProcess('llm');

        if (null === $llmProcess) {
            return;
        }

        $output = $llmProcess->getIncrementalOutput();

        if ('' === $output && '' === $this->llmStdoutBuffer) {
            return;
        }

        // Accumulate with partial-line buffer (same pattern as JsonlProcessAgentSessionClient).
        $this->llmStdoutBuffer .= $output;
        $lastNewline = strrpos($this->llmStdoutBuffer, "\n");
        if (false === $lastNewline) {
            // No complete line yet — wait for more data.
            return;
        }

        $complete = substr($this->llmStdoutBuffer, 0, $lastNewline + 1);
        $this->llmStdoutBuffer = substr($this->llmStdoutBuffer, $lastNewline + 1);

        foreach (explode("\n", $complete) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }

            $data = json_decode($trimmed, true);

            if (!\is_array($data) || !isset($data['v'], $data['type'])) {
                // Not a valid RuntimeEvent — likely messenger:consume noise; skip silently.
                continue;
            }

            try {
                $event = RuntimeEvent::fromArray($data);
                $this->emitter->emit($event);
                $this->consecutiveBadLlmLines = 0;
            } catch (\Throwable $e) {
                ++$this->consecutiveBadLlmLines;

                if ($this->consecutiveBadLlmLines >= $this->maxBadLines) {
                    // Persistent malformed LLM output: delegate capture=0
                    // rethrow to boundary. If we reach here, capture mode.
                    $this->boundary->catch($e, 'headless_controller.llm_stdout_protocol_error', [
                        'consecutive_bad' => $this->consecutiveBadLlmLines,
                    ]);

                    $this->logger->error('Persistent malformed LLM consumer output — streaming may be incomplete', [
                        'consecutive_bad' => $this->consecutiveBadLlmLines,
                        'sample' => mb_substr($trimmed, 0, 200),
                        'exception' => $e,
                    ]);

                    $this->emitter->emit(new RuntimeEvent(
                        type: RuntimeEventTypeEnum::ProtocolError->value,
                        runId: '',
                        seq: 0,
                        payload: [
                            'error' => 'Persistent malformed LLM consumer output — streaming may be incomplete.',
                            'consecutive_bad' => $this->consecutiveBadLlmLines,
                        ],
                    ));
                    $this->consecutiveBadLlmLines = 0;
                }
            }
        }
    }
}
