<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Symfony\AI\Platform\Message\AssistantMessage;

final readonly class LlmStepResult extends AbstractAgentBusMessage
{
    /**
     * @param array<string, int|float>   $usage
     * @param array<string, mixed>|null  $error
     * @param list<ModelNotificationDTO> $modelNotifications                 generic model notifications
     *                                                                       produced by transform context hooks
     *                                                                       during this LLM step
     * @param list<string>               $availableTools                     compact final provider-visible tool names for this request
     * @param int                        $availableToolsSchemaTokensEstimate approximate schema token cost for the final tool set
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
        public string $model = '',
        public string $reasoning = '',
        public array $modelNotifications = [],
        public array $availableTools = [],
        public int $availableToolsSchemaTokensEstimate = 0,
    ) {
        parent::__construct($runId, $turnNo, $stepId, $attempt, $idempotencyKey);
    }
}
