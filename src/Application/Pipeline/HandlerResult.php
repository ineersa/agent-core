<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;

final readonly class HandlerResult
{
    /**
     * @param list<RunEvent>         $events
     * @param list<object>           $effects
     * @param list<object>           $postCommitEffects
     * @param list<callable(): void> $postCommit
     */
    public function __construct(
        public ?RunState $nextState = null,
        public array $events = [],
        public array $effects = [],
        public array $postCommitEffects = [],
        public array $postCommit = [],
        public bool $markHandled = true,
    ) {
    }
}
