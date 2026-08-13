<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Canonical tool-worker → run_control envelope.
 *
 * Ordinary completed tool outcomes use `$result` / `$isError`.
 * When `$pendingHumanInput` is non-null this envelope is a typed NON-TERMINAL
 * human-input suspension: it must not be collected as a finished tool result
 * and must not append a tool message or mark pendingToolCalls complete.
 *
 * `$pendingHumanInput` has no snapshot group attribute and never enters tool-batch
 * session files (run_control uses PhpSerializer, so bus transport is unaffected).
 */
final readonly class ToolCallResult extends AbstractAgentBusMessage
{
    /**
     * @param array<string, mixed>|null $error
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public string $toolCallId,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public int $orderIndex,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public mixed $result = null,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public bool $isError = false,
        #[Groups([ToolBatchStateDTO::SNAPSHOT_GROUP])]
        public ?array $error = null,
        public ?PendingHumanInputRequestDTO $pendingHumanInput = null,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }

    public function isHumanInputSuspension(): bool
    {
        return null !== $this->pendingHumanInput;
    }
}
