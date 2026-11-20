<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

/** Native tool names recognized by the launch-input pipeline. */
final class ChildLaunchToolName
{
    public const string FORK = 'fork';
    public const string SUBAGENT = 'subagent';
}
