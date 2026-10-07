<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;

/**
 * One typed owner decision for normal commit and recovery.
 *
 * Local metadata and synchronous domain coordination stay under the owner lock.
 * Gated invocations use ledger rows; control messages use small outbox records.
 * Broker sends happen only after the outermost owner lock release.
 */
final readonly class TransitionPlan
{
    /**
     * @param list<object>                  $localActions
     * @param list<object>                  $syncActions
     * @param list<object>                  $controlActions
     * @param list<AbstractAgentBusMessage> $gatedEffects
     */
    public function __construct(
        public string $runId,
        public ?VerifiedTransitionDTO $verified,
        public array $localActions,
        public array $syncActions,
        public array $controlActions,
        public array $gatedEffects,
        public ?ExecutionResultDispositionDTO $executionDisposition = null,
    ) {
    }
}
