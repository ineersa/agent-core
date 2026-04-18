<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event\Lifecycle;

use Ineersa\AgentCore\Domain\Event\CoreLifecycleEventType;

final readonly class ToolExecutionEndEvent extends AbstractLifecycleRunEvent
{
    public const string TYPE = CoreLifecycleEventType::TOOL_EXECUTION_END;
}
