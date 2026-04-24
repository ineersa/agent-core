<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Dto;

use Ineersa\AgentCore\Domain\Event\RunEvent;

final readonly class ResolvedReplayEvents
{
    /**
     * @param list<RunEvent> $events
     */
    public function __construct(
        public array $events,
        public string $source,
    ) {
    }
}
