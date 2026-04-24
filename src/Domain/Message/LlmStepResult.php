<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Symfony\AI\Platform\Message\AssistantMessage;

final readonly class LlmStepResult extends AbstractAgentBusMessage
{
    /**
     * @param array<string, int|float>  $usage
     * @param array<string, mixed>|null $error
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
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
