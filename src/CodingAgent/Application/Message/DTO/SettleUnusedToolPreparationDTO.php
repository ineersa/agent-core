<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message\DTO;

use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Metadata-only settlement of unused or ordinarily completed caller preparations.
 *
 * Captured beside FinalizeToolBatchDTO and applied inside the verified metadata
 * transaction. Does not append conversation output or recreate a finished batch.
 */
final readonly class SettleUnusedToolPreparationDTO
{
    /**
     * @param list<string>                                                                          $deferredIds
     * @param list<array{parent_run_id: string, parent_tool_call_id: string, lifecycle_id: string}> $reservedChildFailures
     * @param list<int>                                                                             $unusedNativeRecordIds
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $runId,
        #[Assert\PositiveOrZero]
        public int $turnNo,
        #[Assert\NotBlank]
        public string $stepId,
        #[Assert\All([new Assert\NotBlank(), new Assert\Uuid()])]
        public array $deferredIds = [],
        public array $reservedChildFailures = [],
        #[Assert\All([new Assert\Positive()])]
        public array $unusedNativeRecordIds = [],
        public ?DeferredToolCompletionCorrelation $revisedCorrelation = null,
        public ?ToolPayloadReferenceDTO $revisedBodyReference = null,
        public ?string $revisedToolCallId = null,
        public ?int $expectedOldAttempt = null,
        public ?string $expectedOldIdempotencyKey = null,
        public ?PrepareNativeBashLaunchDTO $revisedNativeLaunch = null,
    ) {
    }
}
