<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Symfony\Component\EventDispatcher\EventDispatcher;

/** Records every dispatched event in order, then dispatches it normally. */
final class RecordingEventDispatcher extends EventDispatcher
{
    /** @var list<object> */
    public array $events = [];

    public function dispatch(object $event, ?string $eventName = null): object
    {
        $this->events[] = $event;

        return parent::dispatch($event, $eventName);
    }
}
