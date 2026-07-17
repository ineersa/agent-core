<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

final readonly class ForkLaunchTaskDTO
{
    /**
     * @param list<AgentMessage>|null $inheritedMessages Already sanitized (and optionally compacted) parent messages
     */
    public function __construct(
        public string $task,
        public ?string $modelOverride = null,
        public ?string $reasoningOverride = null,
        public ?array $inheritedMessages = null,
    ) {
    }
}
