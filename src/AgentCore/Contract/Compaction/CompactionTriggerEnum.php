<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Compaction;

enum CompactionTriggerEnum: string
{
    case Manual = 'manual';
    case Auto = 'auto';
}
