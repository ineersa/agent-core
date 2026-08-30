<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Execution;

use Ineersa\CodingAgent\Repository\RunRelationshipReaderInterface;

/**
 * Honors HATFIELD_AGENTS_DISABLED=1 for global subagent disable.
 *
 * Nested-child launch enforcement belongs to
 * RunRelationshipReaderInterface::requireKnownTopLevel().
 */
final readonly class AgentDepthGuard
{
    /**
     * Returns null when launch is allowed, or an error message when blocked.
     */
    public function checkLaunchAllowed(): ?string
    {
        return $this->agentsGloballyDisabled()
            ? 'Agent subagent launches are globally disabled (HATFIELD_AGENTS_DISABLED=1).'
            : null;
    }

    public function agentsGloballyDisabled(): bool
    {
        $disabled = getenv('HATFIELD_AGENTS_DISABLED');

        if (false === $disabled || '' === $disabled) {
            return false;
        }

        return '1' === $disabled || 'true' === $disabled;
    }
}
