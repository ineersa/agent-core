<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Extension;

use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;

interface HookSubscriberInterface
{
    /**
     * Processes AFTER_TURN_COMMIT hook context.
     */
    public function handleAfterTurnCommit(AfterTurnCommitHookContext $context): AfterTurnCommitHookContext;
}
