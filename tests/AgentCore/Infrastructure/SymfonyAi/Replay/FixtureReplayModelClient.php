<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi\Replay;

use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;

/**
 * ModelClient that records the resolved model and returns fixture usage data.
 *
 * Replaces the HTTP call to the provider API. The real stream is produced
 * by FixtureReplayResultConverter, which feeds fixture deltas through the
 * normal LlmPlatformAdapter::consumeStream() path.
 *
 * Extracted from TraceReplayTest to support multiple replay test classes
 * without code duplication.  Part of the MAINT-05C replay foundation.
 */
final class FixtureReplayModelClient implements ModelClientInterface
{
    public ?string $capturedModel = null;

    /** @var array<string, mixed> */
    public array $capturedOptions = [];

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

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        $this->capturedModel = $model->getName();
        $this->capturedOptions = $options;

        return new InMemoryRawResult([
            'token_usage' => new FixtureTokenUsage(
                promptTokens: $this->fixture['usage']['input_tokens'] ?? null,
                completionTokens: $this->fixture['usage']['output_tokens'] ?? null,
                totalTokens: $this->fixture['usage']['total_tokens'] ?? null,
            ),
        ]);
    }
}

/**
 * Token usage DTO that returns fixture usage values.
 *
 * MAINT-05C note: tool/thinking token fields are not populated from
 * current fixtures.  If future fixtures add these fields, extend this
 * class or add a factory that reads them.
 */
final readonly class FixtureTokenUsage implements \Symfony\AI\Platform\TokenUsage\TokenUsageInterface
{
    public function __construct(
        private ?int $promptTokens = null,
        private ?int $completionTokens = null,
        private ?int $totalTokens = null,
    ) {
    }

    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }

    public function getThinkingTokens(): ?int
    {
        return null;
    }

    public function getToolTokens(): ?int
    {
        return null;
    }

    public function getCachedTokens(): ?int
    {
        return null;
    }

    public function getCacheCreationTokens(): ?int
    {
        return null;
    }

    public function getCacheReadTokens(): ?int
    {
        return null;
    }

    public function getRemainingTokens(): ?int
    {
        return null;
    }

    public function getRemainingTokensMinute(): ?int
    {
        return null;
    }

    public function getRemainingTokensMonth(): ?int
    {
        return null;
    }

    public function getTotalTokens(): ?int
    {
        return $this->totalTokens;
    }
}
