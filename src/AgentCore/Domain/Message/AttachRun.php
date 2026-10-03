<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Message;

final readonly class AttachRun
{
    /** @param list<AgentMessage> $messages */
    public function __construct(public string $runId, public array $messages)
    {
    }
}
