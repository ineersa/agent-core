<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compact durable child lifecycle projection for deferred child runs.
 *
 * Doctrine JSON shape is owned by {@see DeferredChildRunLifecycleProjectionCodec}.
 * Launch model/reasoning are concrete non-empty identity seeded at preparation.
 * Optional enrichment fields are null when absent so Serializer
 * {@see \Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::SKIP_NULL_VALUES}
 * plus codec omission rules preserve historical key omission.
 */
final readonly class DeferredChildRunLifecycleProjectionDTO
{
    public string $model;
    public string $reasoning;

    /**
     * @param list<string>                                 $recentTools
     * @param array<string, DeferredPendingToolCallRowDTO> $pendingToolCalls
     */
    public function __construct(
        #[SerializedName('child_status')]
        public RunStatus $childStatus,
        #[SerializedName('child_turn_no')]
        #[Assert\GreaterThanOrEqual(0)]
        public int $childTurnNo,
        #[SerializedName('last_committed_seq')]
        #[Assert\GreaterThanOrEqual(0)]
        public int $lastCommittedSeq,
        string $model,
        string $reasoning,
        #[SerializedName('error_message')]
        #[Assert\Type('string')]
        public ?string $errorMessage = null,
        #[SerializedName('assistant_result_text')]
        #[Assert\Type('string')]
        public ?string $assistantResultText = null,
        #[SerializedName('assistant_excerpt')]
        #[Assert\Type('string')]
        public ?string $assistantExcerpt = null,
        #[SerializedName('tool_count')]
        #[Assert\GreaterThanOrEqual(0)]
        public int $toolCount = 0,
        #[SerializedName('llm_step_count')]
        #[Assert\GreaterThanOrEqual(0)]
        public int $llmStepCount = 0,
        #[SerializedName('input_tokens')]
        #[Assert\GreaterThanOrEqual(0)]
        public int $inputTokens = 0,
        #[SerializedName('latest_input_tokens')]
        #[Assert\GreaterThanOrEqual(0)]
        public int $latestInputTokens = 0,
        #[SerializedName('context_window')]
        #[Assert\GreaterThanOrEqual(0)]
        public int $contextWindow = 0,
        #[SerializedName('output_tokens')]
        #[Assert\GreaterThanOrEqual(0)]
        public int $outputTokens = 0,
        #[SerializedName('reasoning_tokens')]
        #[Assert\GreaterThanOrEqual(0)]
        public int $reasoningTokens = 0,
        #[SerializedName('total_tokens')]
        #[Assert\GreaterThanOrEqual(0)]
        public int $totalTokens = 0,
        #[Assert\Type('float')]
        public ?float $cost = null,
        #[Assert\Type('string')]
        public ?string $provider = null,
        #[SerializedName('recent_tools')]
        #[Assert\All([new Assert\Type('string')])]
        public array $recentTools = [],
        #[SerializedName('active_tool')]
        #[Assert\Type('string')]
        public ?string $activeToolLine = null,
        #[SerializedName('pending_tool_calls')]
        #[Assert\All([new Assert\Type(DeferredPendingToolCallRowDTO::class)])]
        #[Assert\Valid]
        public array $pendingToolCalls = [],
    ) {
        $model = trim($model);
        $reasoning = trim($reasoning);
        if ('' === $model || '' === $reasoning) {
            throw new \InvalidArgumentException('Deferred child lifecycle projection requires non-empty model and reasoning.');
        }
        $this->model = $model;
        $this->reasoning = $reasoning;
    }
}
