<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract\SubagentProgress;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One child row inside a parallel subagent_progress snapshot.
 *
 * Serialization uses Symfony Serializer ({@see SerializedName}) — no hand-written toArray/fromArray.
 */
final readonly class SubagentProgressChildRowDTO
{
    /**
     * @param list<string>|null $recentTools
     */
    public function __construct(
        #[Assert\Type('int')]
        public int $index = 0,
        #[Assert\Type('string')]
        public string $label = 'Step 0',
        #[SerializedName('agent_name')]
        #[Assert\Type('string')]
        public string $agentName = 'subagent',
        #[Assert\NotBlank]
        public string $status = 'running',
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
        public ?string $reasoning = null,
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
}
