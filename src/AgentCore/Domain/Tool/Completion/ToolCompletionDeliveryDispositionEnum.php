<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool\Completion;

enum ToolCompletionDeliveryDispositionEnum: string
{
    case Pending = 'pending';
    case Applied = 'applied';
    case Parked = 'parked';
    case Rejected = 'rejected';
}
