<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\Tool\ToolCompletionRecipientRepositoryInterface;
use Ineersa\AgentCore\Domain\Coordination\RecordToolCompletionDeliveryDTO;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionRecipientDTO;

final class InMemoryToolCompletionRecipientRepository implements ToolCompletionRecipientRepositoryInterface
{
    /** @var array<string, ToolCompletionRecipientDTO> */
    private array $deliveries = [];

    public function findDelivery(string $destinationRunId, string $messageId): ?ToolCompletionRecipientDTO
    {
        return $this->deliveries[$destinationRunId.'|'.$messageId] ?? null;
    }

    public function prepareProjection(RecordToolCompletionDeliveryDTO $action): \Closure
    {
        return function () use ($action): void {
            $reference = $action->delivery->reference;
            $this->deliveries[$reference->destinationRunId.'|'.$reference->messageId] = $action->delivery;
        };
    }
}
