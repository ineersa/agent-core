<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi\Replay;

use Ineersa\AgentCore\Contract\Hook\LlmStreamObserverInterface;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingSignature;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;

/**
 * Records streaming deltas into a JSON fixture suitable for deterministic replay.
 *
 * Implements {@see LlmStreamObserverInterface} so it receives every delta
 * that flows through {@see LlmPlatformAdapter::consumeStream()}.
 *
 * Usage:
 *   $recorder = new StreamRecorderObserver();
 *   $adapter = new LlmPlatformAdapter(..., streamObserver: $recorder, ...);
 *   $result = $adapter->invoke($request);
 *   $recorder->writeFixture('/path/to/fixture.json', $meta);
 *
 * NEVER used during normal QA — this is opt-in recording only. The observer
 * is a test-only class; it never runs in production.
 */
final class StreamRecorderObserver implements LlmStreamObserverInterface
{
    /** @var list<array<string, mixed>> */
    private array $deltas = [];

    public function onStreamStart(string $runId, ?string $stepId): void
    {
        // Reset state for a new recording.
        $this->deltas = [];
    }

    public function onDelta(string $runId, ?string $stepId, DeltaInterface $delta): void
    {
        $this->deltas[] = $this->deltaToRecord($delta);
    }

    public function onStreamEnd(string $runId, ?string $stepId): void
    {
        // No post-processing needed; fixture is assembled in writeFixture().
    }

    public function onStreamError(string $runId, ?string $stepId, \Throwable $error): void
    {
        // Stream errors are not recorded as fixtures.
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getDeltas(): array
    {
        return $this->deltas;
    }

    /**
     * @param array<string, mixed> $meta Recording metadata (model, provider_id, usage, etc.)
     *
     * @return array<string, mixed> The complete fixture array
     */
    public function buildFixture(array $meta): array
    {
        return array_merge($meta, [
            'deltas' => $this->deltas,
        ]);
    }

    /**
     * Write the recorded fixture to a JSON file.
     *
     * @param array<string, mixed> $meta Recording metadata
     *
     * @return int Bytes written
     */
    public function writeFixture(string $path, array $meta): int
    {
        $fixture = $this->buildFixture($meta);
        $json = json_encode($fixture, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);
        if (false === $json) {
            throw new \RuntimeException('Failed to encode fixture JSON: '.json_last_error_msg());
        }

        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return file_put_contents($path, $json."\n");
    }

    /**
     * Convert a single delta to its fixture-record representation.
     *
     * @return array<string, mixed>
     */
    private function deltaToRecord(DeltaInterface $delta): array
    {
        return match (true) {
            $delta instanceof TextDelta => [
                'type' => 'text',
                'content' => $delta->getText(),
            ],
            $delta instanceof ThinkingDelta => [
                'type' => 'thinking',
                'content' => $delta->getThinking(),
            ],
            $delta instanceof ThinkingSignature => [
                'type' => 'thinking_signature',
                'content' => $delta->getSignature(),
            ],
            $delta instanceof ToolCallStart => [
                'type' => 'tool_call_start',
                'id' => $delta->getId(),
                'name' => $delta->getName(),
            ],
            $delta instanceof ToolInputDelta => [
                'type' => 'tool_input_delta',
                'id' => $delta->getId(),
                'name' => $delta->getName(),
                'partial_json' => $delta->getPartialJson(),
            ],
            $delta instanceof ToolCallComplete => [
                'type' => 'tool_call_complete',
                'tool_calls' => array_map(
                    static fn (\Symfony\AI\Platform\Result\ToolCall $tc): array => [
                        'id' => $tc->getId(),
                        'name' => $tc->getName(),
                        'arguments' => $tc->getArguments(),
                    ],
                    $delta->getToolCalls(),
                ),
            ],
            default => [
                'type' => 'unknown',
                'content' => '',
            ],
        };
    }
}
