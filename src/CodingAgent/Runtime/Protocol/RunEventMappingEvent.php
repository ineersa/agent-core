<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Protocol;

use Ineersa\AgentCore\Domain\Event\RunEvent;

/**
 * Event DTO dispatched through Symfony's EventDispatcher during
 * RunEvent → RuntimeEvent mapping.
 *
 * The event name used for dispatch is the raw AgentCore event type
 * string (e.g., 'run_started', 'llm_step_completed'), allowing
 * subscribers to register for specific event types via
 * getSubscribedEvents().
 *
 * Subscribers that successfully map set $mappedRuntimeEvent and mark
 * handled=true. The first handler wins; subsequent handlers skip via
 * the handled flag check.
 *
 * Events mapped to null (drop) set handled=true but leave
 * mappedRuntimeEvent null; the caller treats this as dropped.
 */
final class RunEventMappingEvent
{
    /** Set by the first subscriber that successfully maps or drops this event. */
    public bool $handled = false;

    public ?RuntimeEvent $mappedRuntimeEvent = null;

    public function __construct(
        public readonly RunEvent $runEvent,
    ) {
    }
}
