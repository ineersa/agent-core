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
     * @param list<string>|null $recentTools
     */
    public function __construct(
        public int $index = 0,
        public string $agentName = 'subagent',
        #[Assert\NotBlank]
        public string $status = 'running',
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
        public ?string $provider = null,
        public ?string $artifactPath = null,
        public ?string $assistantExcerpt = null,
        public ?string $activeTool = null,
    ) {
    }
}
