<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

final readonly class RefreshRunContext extends AbstractAgentBusMessage implements RunControlTransitionMessageInterface
{
    /** @param list<AgentMessage> $messages */
    public function __construct(string $runId, public array $messages)
    {
        parent::__construct($runId, 0, 'refresh_context', 1, '');
    }
}
