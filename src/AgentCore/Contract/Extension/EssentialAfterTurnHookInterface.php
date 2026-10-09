<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Extension;

use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;

/** Prepares data-only owner obligations without dispatching or changing coordination. */
interface EssentialAfterTurnHookInterface
{
    /** @return list<object> */
    public function prepareAfterTurnCommit(AfterTurnCommitHookContext $context, int $predecessorSequence): array;
}
