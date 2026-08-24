<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support\Fake;

use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;

/**
 * Symfony AI token usage double for the shared {@see FakeSymfonyModelClient}.
 */
final readonly class FakeTokenUsage implements TokenUsageInterface
{
    public function __construct(
        private ?int $promptTokens = null,
        private ?int $completionTokens = null,
        private ?int $cachedTokens = null,
        private ?int $cacheReadTokens = null,
        private ?int $cacheCreationTokens = null,
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
        return $this->cachedTokens;
    }

    public function getCacheCreationTokens(): ?int
    {
        return $this->cacheCreationTokens;
    }

    public function getCacheReadTokens(): ?int
    {
        return $this->cacheReadTokens;
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
