<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

enum DeferredToolCompletionStatusEnum: string
{
    case Pending = 'pending';
    case Completed = 'completed';
}
