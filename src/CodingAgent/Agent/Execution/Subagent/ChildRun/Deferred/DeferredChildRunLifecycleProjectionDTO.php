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

    public ?string $errorMessage;

    public ?string $assistantResultText;

    public ?string $assistantExcerpt;

    #[Assert\GreaterThanOrEqual(1)]
    public ?int $contextWindow;

    public ?float $cost;

    #[SerializedName('active_tool')]
    public ?string $activeToolLine;

    /**
     * @param list<string>                                 $recentTools
     * @param array<string, DeferredPendingToolCallRowDTO> $pendingToolCalls
     */
    public function __construct(
        public RunStatus $childStatus,
        #[Assert\GreaterThanOrEqual(0)]
        public int $childTurnNo,
        #[Assert\GreaterThanOrEqual(0)]
        public int $lastCommittedSeq,
        string $model,
        string $reasoning,
        ?string $errorMessage = null,
        ?string $assistantResultText = null,
        ?string $assistantExcerpt = null,
        #[Assert\GreaterThanOrEqual(0)]
        public int $toolCount = 0,
        #[Assert\GreaterThanOrEqual(0)]
        public int $llmStepCount = 0,
        #[Assert\GreaterThanOrEqual(0)]
        public int $inputTokens = 0,
        #[Assert\GreaterThanOrEqual(0)]
        public int $latestInputTokens = 0,
        ?int $contextWindow = null,
        #[Assert\GreaterThanOrEqual(0)]
        public int $outputTokens = 0,
        #[Assert\GreaterThanOrEqual(0)]
        public int $reasoningTokens = 0,
        #[Assert\GreaterThanOrEqual(0)]
        public int $totalTokens = 0,
        ?float $cost = null,
        public array $recentTools = [],
        ?string $activeToolLine = null,
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
