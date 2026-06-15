<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi\Replay;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta;
use Symfony\AI\Platform\Result\Stream\Delta\ThinkingSignature;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete;
use Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart;
use Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Stream result converter that produces deltas from fixture data.
 *
 * Converts fixture delta entries into the corresponding Symfony AI
 * DeltaInterface objects so they flow through
 * LlmPlatformAdapter::consumeStream() exactly as live deltas would.
 *
 * Supported fixture delta types: text, thinking, thinking_signature,
 * tool_call_start, tool_input_delta, tool_call_complete.
 *
 * Extracted from TraceReplayTest for reuse across replay test classes.
 */
final class FixtureReplayResultConverter implements ResultConverterInterface
{
    /**
     * @param array<string, mixed> $fixture The loaded fixture data
     */
    public function __construct(
        public readonly array $fixture,
    ) {
    }

    public function supports(Model $model): bool
    {
        return true;
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        unset($result, $options);

        return new StreamResult((function (): \Generator {
            foreach ($this->fixture['deltas'] ?? [] as $delta) {
                $type = $delta['type'] ?? 'text';
                $content = $delta['content'] ?? '';

                yield match ($type) {
                    'text' => new TextDelta($content),
                    'thinking' => new ThinkingDelta($content),
                    'thinking_delta' => new ThinkingDelta($content),
                    'thinking_signature' => new ThinkingSignature($content),
                    'tool_call_start' => new ToolCallStart(
                        $delta['id'] ?? '',
                        $delta['name'] ?? '',
                    ),
                    'tool_input_delta' => new ToolInputDelta(
                        $delta['id'] ?? '',
                        $delta['name'] ?? '',
                        $delta['partial_json'] ?? '',
                    ),
                    'tool_call_complete' => new ToolCallComplete(
                        array_map(
                            static fn (array $tc): \Symfony\AI\Platform\Result\ToolCall => new \Symfony\AI\Platform\Result\ToolCall(
                                $tc['id'],
                                $tc['name'],
                                $tc['arguments'] ?? [],
                            ),
                            $delta['tool_calls'] ?? [],
                        ),
                    ),
                    default => new TextDelta($content),
                };
            }
        })());
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return new class($this->fixture) implements TokenUsageExtractorInterface {
            /** @param array<string, mixed> $fixture */
            public function __construct(private readonly array $fixture)
            {
            }

            public function extract(RawResultInterface $rawResult, array $options = []): ?TokenUsageInterface
            {
                unset($options);

                $data = $rawResult->getData();
                $tokenUsage = $data['token_usage'] ?? null;

                return $tokenUsage instanceof TokenUsageInterface ? $tokenUsage : null;
            }
        };
    }
}
