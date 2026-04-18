<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Tool;

enum ToolExecutionMode: string
{
    case Sequential = 'sequential';
    case Parallel = 'parallel';
    case Interrupt = 'interrupt';
}
