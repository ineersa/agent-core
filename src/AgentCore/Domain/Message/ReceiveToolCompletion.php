<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;

/** Runtime-only instruction. User commands cannot construct this path. */
final readonly class ReceiveToolCompletion extends AbstractAgentBusMessage
{
    public function __construct(public ToolCompletionDeliveryReferenceDTO $reference)
    {
        parent::__construct($reference->destinationRunId, $reference->sourceEvent->turnNo, 'tool-completion-receipt', 1, $reference->messageId);
    }
}
