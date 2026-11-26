<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\Hatfield\ExtensionApi\Session\Event\AssistantMessageCompletedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\BackgroundJobEndedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\BackgroundJobStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ChildExecutionEndedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ChildExecutionStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputRequestedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputResolvedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\SessionClosingEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\SessionSelectedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ToolFinishedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ToolStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnCancelledEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnCompletedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnFailedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnStartedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/** Records every public session event in dispatch order. */
final class SessionEventRecorder implements EventSubscriberInterface
{
    /** @var list<object> */
    public array $events = [];

    public static function getSubscribedEvents(): array
    {
        return array_fill_keys([
            SessionSelectedEvent::class, SessionClosingEvent::class, TurnStartedEvent::class, TurnCompletedEvent::class,
            TurnFailedEvent::class, TurnCancelledEvent::class, AssistantMessageCompletedEvent::class, ToolStartedEvent::class,
            ToolFinishedEvent::class, InputRequestedEvent::class, InputResolvedEvent::class, ChildExecutionStartedEvent::class,
            ChildExecutionEndedEvent::class, BackgroundJobStartedEvent::class, BackgroundJobEndedEvent::class,
        ], 'record');
    }

    public function record(object $event): void
    {
        $this->events[] = $event;
    }
}
