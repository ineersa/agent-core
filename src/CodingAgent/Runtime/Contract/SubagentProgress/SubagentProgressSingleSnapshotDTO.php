<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\SubagentProgress;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Single-child parent subagent_progress snapshot.
 *
 * Optional enrichment fields are null when absent so Symfony Serializer
 * {@see AbstractObjectNormalizer::SKIP_NULL_VALUES} preserves historical key omission.
 */
final readonly class SubagentProgressSingleSnapshotDTO implements SubagentProgressSnapshotInterface
{
    /**
     * @param list<string>|null $recentTools
     */
    public function __construct(
        #[Assert\EqualTo('single')]
        public string $mode,
        #[Assert\NotBlank]
        public string $status,
        #[Assert\GreaterThanOrEqual(0)]
        public int $elapsedMs = 0,
        #[Assert\NotBlank]
        public string $agentName = 'subagent',
        public string $artifactId = '',
        public string $agentRunId = '',
        public string $taskSummary = '',
        public int $turnNo = 0,
        public ?int $toolCount = null,
        public ?int $llmStepCount = null,
        public ?int $inputTokens = null,
        public ?int $latestInputTokens = null,
        public ?int $outputTokens = null,
        public ?int $reasoningTokens = null,
        public ?int $totalTokens = null,
        #[Assert\All([new Assert\Type('string')])]
        public ?array $recentTools = null,
        public ?float $cost = null,
        public ?string $model = null,
        public ?string $reasoning = null,
        public ?int $contextWindow = null,
        public ?string $artifactPath = null,
        public ?string $assistantExcerpt = null,
        public ?string $activeTool = null,
    ) {
    }

    public function status(): string
    {
        return $this->status;
    }

    public function isParallel(): bool
    {
        return false;
    }
}
