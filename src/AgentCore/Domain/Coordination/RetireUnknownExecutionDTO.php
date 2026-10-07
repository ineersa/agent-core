<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown;

/** Explicit owner repair decision. Retirement preserves the old execution receipt. */
final readonly class RetireUnknownExecutionDTO
{
    public const string WARNING = 'Explicit repair retired an unknown execution after worker exclusion. Side effects may already have occurred; another attempt may duplicate them.';

    public function __construct(public ExecutionOutcomeUnknown $notice)
    {
    }

    public function verifyTransition(VerifiedTransitionDTO $transition): void
    {
        if (($transition->work['run_id'] ?? null) !== $this->notice->runId() || ($transition->work['source']['command_type'] ?? null) !== self::class) {
            throw new \RuntimeException('Unknown retirement requires an explicit verified owner repair decision.');
        }
        foreach ($transition->work['actions'] ?? [] as $action) {
            if ($action instanceof self && (array) $action->notice === (array) $this->notice) {
                return;
            }
        }
        throw new \RuntimeException('Unknown retirement is absent from its verified repair transition.');
    }
}
