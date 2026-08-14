<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\SubagentProgress;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * One child row inside a parallel subagent_progress snapshot.
 */
final readonly class SubagentProgressChildRowDTO
{
    /**
     * @param list<string> $recentTools
     */
    public function __construct(
        #[Assert\GreaterThanOrEqual(1)]
        public int $index,
        #[Assert\NotBlank]
        public string $agentName,
        #[Assert\NotBlank]
        public string $status,
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
}
