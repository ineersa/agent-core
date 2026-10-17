<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Coordination\RecordToolCompletionDeliveryDTO;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionRecipientDTO;

interface ToolCompletionRecipientRepositoryInterface
{
    public function findDelivery(string $destinationRunId, string $messageId): ?ToolCompletionRecipientDTO;

    /** Serializes captured metadata before the shared transaction starts. */
    public function prepareProjection(RecordToolCompletionDeliveryDTO $action): \Closure;
}
