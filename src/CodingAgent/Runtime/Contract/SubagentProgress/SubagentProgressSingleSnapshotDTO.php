<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\SubagentProgress;

use Symfony\Component\Serializer\Attribute\SerializedName;
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
        #[SerializedName('elapsed_ms')]
        #[Assert\GreaterThanOrEqual(0)]
        public int $elapsedMs = 0,
        #[SerializedName('agent_name')]
        #[Assert\NotBlank]
        public string $agentName = 'subagent',
        #[SerializedName('artifact_id')]
        #[Assert\Type('string')]
        public string $artifactId = '',
        #[SerializedName('agent_run_id')]
        #[Assert\Type('string')]
        public string $agentRunId = '',
        #[SerializedName('task_summary')]
        #[Assert\Type('string')]
        public string $taskSummary = '',
        #[SerializedName('turn_no')]
        #[Assert\Type('int')]
        public int $turnNo = 0,
        #[SerializedName('tool_count')]
        #[Assert\Type('int')]
        public ?int $toolCount = null,
        #[SerializedName('llm_step_count')]
        #[Assert\Type('int')]
        public ?int $llmStepCount = null,
        #[SerializedName('input_tokens')]
        #[Assert\Type('int')]
        public ?int $inputTokens = null,
        #[SerializedName('latest_input_tokens')]
        #[Assert\Type('int')]
        public ?int $latestInputTokens = null,
        #[SerializedName('output_tokens')]
        #[Assert\Type('int')]
        public ?int $outputTokens = null,
        #[SerializedName('reasoning_tokens')]
        #[Assert\Type('int')]
        public ?int $reasoningTokens = null,
        #[SerializedName('total_tokens')]
        #[Assert\Type('int')]
        public ?int $totalTokens = null,
        #[SerializedName('recent_tools')]
        #[Assert\All([new Assert\Type('string')])]
        public ?array $recentTools = null,
        #[Assert\Type('float')]
        public ?float $cost = null,
        #[Assert\Type('string')]
        public ?string $model = null,
        #[SerializedName('context_window')]
        #[Assert\Type('int')]
        public ?int $contextWindow = null,
        #[Assert\Type('string')]
        public ?string $provider = null,
        #[SerializedName('artifact_path')]
        #[Assert\Type('string')]
        public ?string $artifactPath = null,
        #[SerializedName('assistant_excerpt')]
        #[Assert\Type('string')]
        public ?string $assistantExcerpt = null,
        #[SerializedName('active_tool')]
        #[Assert\Type('string')]
        public ?string $activeTool = null,
    ) {
    }

    public function mode(): string
    {
        return $this->mode;
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
