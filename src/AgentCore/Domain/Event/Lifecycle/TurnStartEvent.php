<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event\Lifecycle;

use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;

final readonly class TurnStartEvent extends AbstractLifecycleRunEvent
{
    public const string TYPE = RunEventTypeEnum::TurnStart->value;
}
