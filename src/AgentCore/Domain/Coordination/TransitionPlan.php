<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

use Ineersa\AgentCore\Domain\Coordination\DTO\VerifiedTransitionDTO;

/** Captured owner coordination and ordinary messages sent after lock release. */
final readonly class TransitionPlan
{
    /**
     * @param list<object> $localActions
     * @param list<object> $syncActions
     * @param list<object> $messages
     */
    public function __construct(
        public string $runId,
        public ?VerifiedTransitionDTO $verified,
        public array $localActions,
        public array $syncActions,
        public array $messages,
    ) {
    }
}
