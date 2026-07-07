<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Psr\Log\LoggerInterface;
use Revolt\EventLoop;

/**
 * Polls all controller-owned messenger consumer stdout pipes for RuntimeEvent JSONL.
 *
 * Committed canonical events and transient LLM streaming deltas both arrive on consumer
 * stdout after durable append (decorator) or stream subscribers respectively.
 */
final class ConsumerStdoutPoller
{
    private const int MAX_CONSECUTIVE_BAD_LINES = 10;

    /** Guard against unbounded partial-line retention when stdout lacks newlines. */
    private const int MAX_PARTIAL_STDOUT_BYTES = ConsumerSupervisor::PARTIAL_STDOUT_MAX_BYTES;

    /** @var array<string, string> consumerKey => partial line buffer */
    private array $stdoutBuffers = [];

    /** @var array<string, int> consumerKey => consecutive unparseable lines */
    private array $consecutiveBadLines = [];

    public function __construct(
        private readonly ConsumerStdoutSourceInterface $stdoutSource,
        private readonly RuntimeEventEmitter $emitter,
        private readonly RuntimeExceptionBoundary $boundary,
        private readonly LoggerInterface $logger,
        private readonly int $maxBadLines = self::MAX_CONSECUTIVE_BAD_LINES,
    ) {
    }

    public function startPollLoop(float $interval = 0.01): void
    {
        EventLoop::repeat($interval, function (): void {
            if ($this->emitter->isShuttingDown()) {
                return;
            }

            $this->pollOnce();
        });
    }

    public function pollOnce(): void
    {
        foreach ($this->stdoutSource->readIncrementalStdoutByConsumer() as $consumerKey => $output) {
            if ('' === $output && '' === ($this->stdoutBuffers[$consumerKey] ?? '')) {
                continue;
            }

            $this->pollConsumerStdout($consumerKey, $output);
        }
    }

    private function pollConsumerStdout(string $consumerKey, string $output): void
    {
        $buffer = ($this->stdoutBuffers[$consumerKey] ?? '').$output;
        $lastNewline = strrpos($buffer, "\n");
        if (false === $lastNewline) {
            if (\strlen($buffer) > self::MAX_PARTIAL_STDOUT_BYTES) {
                $this->logger->warning('Truncating oversized partial consumer stdout buffer', [
                    'consumer_key' => $consumerKey,
                    'bytes' => \strlen($buffer),
                    'max_bytes' => self::MAX_PARTIAL_STDOUT_BYTES,
                    'component' => 'ConsumerStdoutPoller',
                    'event_type' => 'consumer_stdout.partial_buffer_truncated',
                ]);
                $buffer = substr($buffer, -self::MAX_PARTIAL_STDOUT_BYTES);
            }
            $this->stdoutBuffers[$consumerKey] = $buffer;

            return;
        }

        $complete = substr($buffer, 0, $lastNewline + 1);
        $this->stdoutBuffers[$consumerKey] = substr($buffer, $lastNewline + 1);

        foreach (explode("\n", $complete) as $line) {
            $trimmed = trim($line);
            if ('' === $trimmed) {
                continue;
            }

            $data = json_decode($trimmed, true);
            if (!\is_array($data) || !isset($data['v'], $data['type'])) {
                continue;
            }

            try {
                $event = RuntimeEvent::fromArray($data);
                $this->emitter->emit($event);
                $this->consecutiveBadLines[$consumerKey] = 0;
            } catch (\Throwable $e) {
                $bad = ($this->consecutiveBadLines[$consumerKey] ?? 0) + 1;
                $this->consecutiveBadLines[$consumerKey] = $bad;

                $this->logger->warning('Skipping unparseable JSONL from consumer stdout', [
                    'consumer_key' => $consumerKey,
                    'line' => mb_substr($trimmed, 0, 200),
                    'exception' => $e,
                    'consecutive_bad' => $bad,
                    'component' => 'ConsumerStdoutPoller',
                    'event_type' => 'consumer_stdout.unparseable_line',
                ]);

                if ($bad >= $this->maxBadLines) {
                    $this->boundary->catch($e, 'headless_controller.consumer_stdout_protocol_error', [
                        'consumer_key' => $consumerKey,
                        'consecutive_bad' => $bad,
                    ]);

                    $this->emitter->emit(new RuntimeEvent(
                        type: RuntimeEventTypeEnum::ProtocolError->value,
                        runId: '',
                        seq: 0,
                        payload: [
                            'error' => \sprintf(
                                'Persistent malformed consumer stdout output (key=%s) — runtime events may be incomplete.',
                                $consumerKey,
                            ),
                            'consumer_key' => $consumerKey,
                            'consecutive_bad' => $bad,
                        ],
                    ));
                    $this->consecutiveBadLines[$consumerKey] = 0;
                }
            }
        }
    }
}
