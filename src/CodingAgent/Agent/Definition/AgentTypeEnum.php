<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Definition;

/**
 * Agent type classification.
 *
 * Determines the agent's default capabilities, tooling, and behaviour.
 * The 'custom' type exists for user/project-defined agents that do not
 * inherit any builtin presets.
 */
enum AgentTypeEnum: string
{
    case Scout = 'scout';
    case Reviewer = 'reviewer';
    case Researcher = 'researcher';
    case Worker = 'worker';
    case Custom = 'custom';
}
