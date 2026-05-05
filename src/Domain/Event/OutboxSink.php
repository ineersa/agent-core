<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

enum OutboxSink: string
{
    case Jsonl = 'jsonl';
}
