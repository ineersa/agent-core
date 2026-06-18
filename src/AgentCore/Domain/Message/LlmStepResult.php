<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Ineersa\AgentCore\Domain\Model\ModelInputMessageDTO;
use Symfony\AI\Platform\Message\AssistantMessage;

final readonly class LlmStepResult extends AbstractAgentBusMessage
{
    /**
     * @param array<string, int|float>   $usage
     * @param array<string, mixed>|null  $error
     * @param list<ModelInputMessageDTO> $modelInputMessages
     */
    public function __construct(
        string $runId,
        int $turnNo,
        string $stepId,
        int $attempt,
        string $idempotencyKey,
        public ?AssistantMessage $assistantMessage = null,
        public array $usage = [],
        public ?string $stopReason = null,
        public ?array $error = null,
        public ?string $toolsRef = null,
        public array $modelInputMessages = [],
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
