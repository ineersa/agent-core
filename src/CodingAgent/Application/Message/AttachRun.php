<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message;

use Ineersa\AgentCore\Domain\Message\AgentMessage;

final readonly class AttachRun
{
    /** @param list<AgentMessage> $messages */
    public function __construct(public string $runId, public array $messages, public string $commandId)
    {
    }
}
