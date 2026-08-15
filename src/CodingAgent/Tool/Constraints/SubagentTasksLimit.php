<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Constraints;

use Symfony\Component\Validator\Constraint;

/**
 * Rejects a `subagent` parallel `tasks` list longer than AgentsConfig maxAgents.
 *
 * The bound is settings-derived (agents.max_agents), so it cannot be a
 * static Assert option; the validator autowires the same config object the
 * provider-visible schema fragment comes from
 * ({@see \Ineersa\CodingAgent\Tool\Schema\SubagentTasksSchemaProvider}), so the
 * schema `maxItems` and the runtime limit can never drift.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class SubagentTasksLimit extends Constraint
{
    public string $message = 'Parallel subagent execution supports at most {{ limit }} agents per tool call, but {{ count }} tasks were requested.';
}
