<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\SubagentProgress;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Single-child parent subagent_progress snapshot.
 *
 * Counters and recentTools are concrete so consumers need no null recovery.
 * Optional enrichment fields stay null when absent so Serializer
 * {@see AbstractObjectNormalizer::SKIP_NULL_VALUES} omits them on the wire.
 */
final readonly class SubagentProgressSingleSnapshotDTO implements SubagentProgressSnapshotInterface
{
    /**
     * @param list<string> $recentTools
     */
    public function __construct(
        #[Assert\EqualTo('single')]
        public string $mode,
        #[Assert\NotBlank]
        public string $status,
        #[Assert\NotBlank]
        public string $agentName,
        #[Assert\NotBlank]
        public string $artifactId,
        #[Assert\NotBlank]
        public string $agentRunId,
        #[Assert\NotBlank]
        public string $taskSummary,
        #[Assert\NotBlank]
        public string $model,
        #[Assert\NotBlank]
        public string $reasoning,
        #[Assert\GreaterThanOrEqual(0)]
        public int $elapsedMs = 0,
        public int $turnNo = 0,
        #[Assert\GreaterThanOrEqual(0)]
        public int $toolCount = 0,
        #[Assert\GreaterThanOrEqual(0)]
        public int $llmStepCount = 0,
        #[Assert\GreaterThanOrEqual(0)]
        public int $inputTokens = 0,
        #[Assert\GreaterThanOrEqual(0)]
        public int $latestInputTokens = 0,
        #[Assert\GreaterThanOrEqual(0)]
        public int $outputTokens = 0,
        #[Assert\GreaterThanOrEqual(0)]
        public int $reasoningTokens = 0,
        #[Assert\GreaterThanOrEqual(0)]
        public int $totalTokens = 0,
        /** @var list<string> */
        #[Assert\All([new Assert\NotBlank()])]
        public array $recentTools = [],
        public ?float $cost = null,
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
