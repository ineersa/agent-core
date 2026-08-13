<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\SubagentProgress;

use Symfony\Component\Serializer\Attribute\SerializedName;
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
        #[SerializedName('agent_name')]
        public string $agentName = 'subagent',
        #[Assert\NotBlank]
        public string $status = 'running',
        #[SerializedName('artifact_id')]
        public string $artifactId = '',
        #[SerializedName('agent_run_id')]
        public string $agentRunId = '',
        #[SerializedName('task_summary')]
        public string $taskSummary = '',
        #[SerializedName('turn_no')]
        public int $turnNo = 0,
        #[SerializedName('tool_count')]
        public ?int $toolCount = null,
        #[SerializedName('llm_step_count')]
        public ?int $llmStepCount = null,
        #[SerializedName('input_tokens')]
        public ?int $inputTokens = null,
        #[SerializedName('latest_input_tokens')]
        public ?int $latestInputTokens = null,
        #[SerializedName('output_tokens')]
        public ?int $outputTokens = null,
        #[SerializedName('reasoning_tokens')]
        public ?int $reasoningTokens = null,
        #[SerializedName('total_tokens')]
        public ?int $totalTokens = null,
        #[SerializedName('recent_tools')]
        #[Assert\All([new Assert\Type('string')])]
        public ?array $recentTools = null,
        public ?float $cost = null,
        public ?string $model = null,
        public ?string $reasoning = null,
        #[SerializedName('context_window')]
        public ?int $contextWindow = null,
        public ?string $provider = null,
        #[SerializedName('artifact_path')]
        public ?string $artifactPath = null,
        #[SerializedName('assistant_excerpt')]
        public ?string $assistantExcerpt = null,
        #[SerializedName('active_tool')]
        public ?string $activeTool = null,
    ) {
    }
}
