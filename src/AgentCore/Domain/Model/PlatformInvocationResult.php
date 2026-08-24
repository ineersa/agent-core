<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Model;

use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;

final readonly class PlatformInvocationResult
{
    /**
     * @param list<DeltaInterface>       $deltas
     * @param array<string, int|float>   $usage
     * @param array<string, mixed>|null  $error
     * @param list<ModelNotificationDTO> $modelNotifications                 generic model notifications
     *                                                                       produced by transform context hooks
     * @param list<string>               $availableTools                     compact final provider-visible tool names for this request
     * @param int                        $availableToolsSchemaTokensEstimate approximate schema token cost for the final tool set
     */
    public function __construct(
        public ?AssistantMessage $assistantMessage,
        public array $deltas = [],
        public array $usage = [],
        public ?string $stopReason = null,
        public ?array $error = null,
        public string $model = '',
        public string $reasoning = '',
        public array $modelNotifications = [],
        public array $availableTools = [],
        public int $availableToolsSchemaTokensEstimate = 0,
    ) {
    }

    /**
     * @return list<DeltaInterface>
     */
    public function deltas(): array
    {
        return $this->deltas;
    }
}
