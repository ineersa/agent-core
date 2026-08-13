<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Compact durable child lifecycle projection for deferred child runs.
 *
 * Doctrine JSON column stores the Serializer-normalized shape of this DTO.
 * Launch model/reasoning are required non-empty identity seeded at preparation.
 * Optional enrichment fields are null when absent so
 * {@see \Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer::SKIP_NULL_VALUES}
 * omits them on write.
 */
final readonly class DeferredChildRunLifecycleProjectionDTO
{
    public string $model;
    public string $reasoning;

    #[SerializedName('error_message')]
    public ?string $errorMessage;

    #[SerializedName('assistant_result_text')]
    public ?string $assistantResultText;

    #[SerializedName('assistant_excerpt')]
    public ?string $assistantExcerpt;

    #[SerializedName('context_window')]
    #[Assert\GreaterThanOrEqual(1)]
    public ?int $contextWindow;

    public ?float $cost;

    public ?string $provider;

    #[SerializedName('active_tool')]
    public ?string $activeToolLine;

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
        ?string $errorMessage = null,
        ?string $assistantResultText = null,
        ?string $assistantExcerpt = null,
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
        ?int $contextWindow = null,
        #[SerializedName('output_tokens')]
        #[Assert\GreaterThanOrEqual(0)]
        public int $outputTokens = 0,
        #[SerializedName('reasoning_tokens')]
        #[Assert\GreaterThanOrEqual(0)]
        public int $reasoningTokens = 0,
        #[SerializedName('total_tokens')]
        #[Assert\GreaterThanOrEqual(0)]
        public int $totalTokens = 0,
        ?float $cost = null,
        ?string $provider = null,
        #[SerializedName('recent_tools')]
        public array $recentTools = [],
        ?string $activeToolLine = null,
        #[SerializedName('pending_tool_calls')]
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
        $this->errorMessage = self::nullIfEmptyString($errorMessage);
        $this->assistantResultText = self::nullIfEmptyString($assistantResultText);
        $this->assistantExcerpt = self::nullIfEmptyString($assistantExcerpt);
        $this->provider = self::nullIfEmptyString($provider);
        $this->activeToolLine = self::nullIfEmptyString($activeToolLine);
        $this->contextWindow = (null !== $contextWindow && $contextWindow > 0) ? $contextWindow : null;
        $this->cost = (null !== $cost && $cost > 0.0) ? $cost : null;
    }

    private static function nullIfEmptyString(?string $value): ?string
    {
        if (null === $value || '' === $value) {
            return null;
        }

        return $value;
    }
}
