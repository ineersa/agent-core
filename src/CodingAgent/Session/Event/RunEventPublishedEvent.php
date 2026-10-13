<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Event;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/** The verified hot batch supplies bootstrap projection without an archive reread. */
final readonly class RunEventPublishedEvent
{
    public function __construct(public RunEvent $event)
    {
    }
}
