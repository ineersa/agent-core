<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

/**
 * Discriminator for the type of agent artifact/child run.
 *
 * - Subagent: a standard child agent (v1 foreground subagent)
 * - Fork:     a new fork process (planned for later fork runtime)
 */
enum AgentArtifactKindEnum: string
{
    case Subagent = 'subagent';
    case Fork = 'fork';
}
