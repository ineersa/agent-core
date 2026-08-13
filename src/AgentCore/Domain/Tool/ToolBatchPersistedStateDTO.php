<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Snapshot batch_state blob shape (snake_case top-level keys).
 *
 * Dynamic ID maps keep array keys; values are typed fixed rows.
 */
final readonly class ToolBatchPersistedStateDTO
{
    /**
     * @param array<string, int>                   $expectedOrder
     * @param array<string, ToolBatchCallRowDTO>   $callData
     * @param list<string>                         $pendingQueue
     * @param array<string, true>                  $inFlight
     * @param array<string, ToolBatchResultRowDTO> $resultData
     * @param array<string, string>                $awaitingHumanInput
     */
    public function __construct(
        #[SerializedName('expected_order')]
        #[Assert\Type('array')]
        public array $expectedOrder,
        #[SerializedName('call_data')]
        #[Assert\Type('array')]
        #[Assert\All([new Assert\Type(ToolBatchCallRowDTO::class)])]
        #[Assert\Valid]
        public array $callData,
        #[SerializedName('pending_queue')]
        #[Assert\Type('array')]
        #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]
        public array $pendingQueue,
        #[SerializedName('in_flight')]
        #[Assert\Type('array')]
        public array $inFlight,
        #[SerializedName('result_data')]
        #[Assert\Type('array')]
        #[Assert\All([new Assert\Type(ToolBatchResultRowDTO::class)])]
        #[Assert\Valid]
        public array $resultData,
        #[Assert\Type('bool')]
        public bool $finalized,
        #[SerializedName('max_parallelism')]
        #[Assert\Type('integer')]
        #[Assert\GreaterThanOrEqual(1)]
        public int $maxParallelism,
        #[SerializedName('awaiting_human_input')]
        #[Assert\Type('array')]
        public array $awaitingHumanInput,
    ) {
    }
}
