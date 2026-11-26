<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Session;

use Ineersa\CodingAgent\Extension\Session\ExtensionSessionEventPublisher;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum as Type;
use Ineersa\CodingAgent\Tests\Support\SessionEventRecorder;
use Ineersa\Hatfield\ExtensionApi\Session\Event\AssistantMessageCompletedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\BackgroundJobEndedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ChildExecutionStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputRequestedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputRequestTypeEnum;
use Ineersa\Hatfield\ExtensionApi\Session\Event\InputResolvedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\SessionClosingEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\SessionSelectedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ToolFinishedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\ToolStartedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnCompletedEvent;
use Ineersa\Hatfield\ExtensionApi\Session\Event\TurnStartedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\ErrorHandler\BufferingLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ExtensionSessionEventPublisherTest extends TestCase
{
    public function testPublishesLiveMainEventsOfTheSelectedSessionOnce(): void
    {
        $dispatcher = new EventDispatcher();
        $recorder = new SessionEventRecorder();
        $dispatcher->addSubscriber($recorder);
        $publisher = new ExtensionSessionEventPublisher($dispatcher, new NullLogger());
        $publisher->publish(new RuntimeEvent(Type::TurnStarted->value, 's', 1));
        $publisher->select('s', true);
        $publisher->select('s', false);
        $child = ['child_run_id' => 'child', 'child_sequence' => 4];
        foreach ([
            new RuntimeEvent(Type::TurnStarted->value, 's', 2, ['trigger' => 'schedule', 'prompt' => 'private']),
            new RuntimeEvent(Type::TurnStarted->value, 's', 2),
            new RuntimeEvent(Type::TurnStarted->value, 'child', 3),
            new RuntimeEvent(Type::AssistantMessageCompleted->value, 's', 3, ['content' => [['type' => 'text', 'text' => 'First'], ['type' => 'tool_call'], ['type' => 'text', 'text' => 'Second']]]),
            new RuntimeEvent(Type::AssistantMessageCompleted->value, 's', 4, ['text' => 'Flat', 'content' => [['type' => 'text', 'text' => 'Ignored']]]),
            new RuntimeEvent(Type::HumanInputRequested->value, 's', 5, ['question_id' => 'q']),
            new RuntimeEvent(Type::HumanInputRequested->value, 's', 6, ['request_id' => 'r', 'question_id' => 'q', 'approval_context' => []]),
            new RuntimeEvent(Type::ApprovalApproved->value, 's', 7, ['request_id' => 'r']),
            new RuntimeEvent(Type::ToolExecutionStarted->value, 's', 8, ['tool_call_id' => 'call', 'tool_name' => 'bash', 'arguments' => 'private']),
            new RuntimeEvent(Type::ToolExecutionFailed->value, 's', 9, ['tool_call_id' => 'call']),
            new RuntimeEvent(Type::ToolExecutionFailed->value, 's', 10),
            new RuntimeEvent(Type::ChildExecutionStarted->value, 's', 0, $child),
            new RuntimeEvent(Type::ChildExecutionStarted->value, 's', 0, ['child_run_id' => 's', 'child_sequence' => 4]),
            new RuntimeEvent(Type::ChildInputObserved->value, 's', 0, $child + ['phase' => 'permission', 'request_id' => 'cr']),
            new RuntimeEvent(Type::BackgroundJobEnded->value, 's', 0, ['owner_run_id' => 'child', 'job_id' => 'bash:1']),
            new RuntimeEvent(Type::BackgroundJobEnded->value, 's', 0, ['owner_run_id' => 'child', 'job_id' => 'bad id']),
            new RuntimeEvent(Type::RunCompleted->value, 's', 11),
            new RuntimeEvent(Type::RunCompleted->value, 's', 11),
        ] as $event) {
            $publisher->publish($event);
        }

        $this->assertEquals([
            new SessionSelectedEvent('s', true),
            new TurnStartedEvent('s', 2, 'schedule'),
            new AssistantMessageCompletedEvent('s', 3, "First\n\nSecond"),
            new AssistantMessageCompletedEvent('s', 4, 'Flat'),
            new InputRequestedEvent('s', 5, 'q', InputRequestTypeEnum::Question),
            new InputRequestedEvent('s', 6, 'r', InputRequestTypeEnum::Approval),
            new InputResolvedEvent('s', 7, 'r'),
            new ToolStartedEvent('s', 8, 'call', 'bash'),
            new ToolFinishedEvent('s', 9, 'call'),
            new ChildExecutionStartedEvent('s', 4, 'child'),
            new InputRequestedEvent('s', 4, 'cr', InputRequestTypeEnum::Approval, 'child'),
            new BackgroundJobEndedEvent('s', 'child', 'bash:1'),
            new TurnCompletedEvent('s', 11),
        ], $recorder->events);
    }

    public function testSessionBoundariesResetSelectionAndOnlyCloseDispatchesClosing(): void
    {
        $dispatcher = new EventDispatcher();
        $recorder = new SessionEventRecorder();
        $dispatcher->addSubscriber($recorder);
        $publisher = new ExtensionSessionEventPublisher($dispatcher, new NullLogger());
        $publisher->select('s', false);
        $publisher->publish(new RuntimeEvent(Type::TurnStarted->value, 's', 5));
        $publisher->resumeAfter(3);
        $publisher->publish(new RuntimeEvent(Type::RunCompleted->value, 's', 6));
        $publisher->resumeAfter(8);
        $publisher->publish(new RuntimeEvent(Type::RunCompleted->value, 's', 8));
        $publisher->clear();
        $this->assertSame('', $publisher->sessionId());
        $publisher->publish(new RuntimeEvent(Type::TurnStarted->value, 's', 6));
        $publisher->close();
        $publisher->select('s', true);
        // A new controller replays from the start of the selection.
        $publisher->publish(new RuntimeEvent(Type::TurnStarted->value, 's', 1));
        $publisher->close();
        $this->assertSame('', $publisher->sessionId());

        $this->assertEquals([
            new SessionSelectedEvent('s', false),
            new TurnStartedEvent('s', 5, null),
            new TurnCompletedEvent('s', 6),
            new SessionSelectedEvent('s', true),
            new TurnStartedEvent('s', 1, null),
            new SessionClosingEvent('s'),
        ], $recorder->events);
    }

    public function testListenerFailureIsLoggedWithoutStoppingLaterListenersOrPublication(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(TurnStartedEvent::class, static function (): void {
            throw new \RuntimeException('private listener failure');
        }, 10);
        $recorder = new SessionEventRecorder();
        $dispatcher->addSubscriber($recorder);
        $logger = new BufferingLogger();
        $publisher = new ExtensionSessionEventPublisher($dispatcher, $logger);
        $publisher->select('s', false);
        $publisher->publish(new RuntimeEvent(Type::TurnStarted->value, 's', 1));
        $publisher->publish(new RuntimeEvent(Type::TurnStarted->value, 's', 2));

        $this->assertEquals([
            new SessionSelectedEvent('s', false),
            new TurnStartedEvent('s', 1, null),
            new TurnStartedEvent('s', 2, null),
        ], $recorder->events, 'A throwing listener does not hide the event from later listeners.');

        $logs = $logger->cleanLogs();
        $this->assertCount(2, $logs);
        $this->assertSame(['warning', 'extension.session_event_listener_failed', [
            'component' => 'extension_session_events', 'event_type' => 'session_event_listener_failed',
            'run_id' => 's', 'session_id' => 's',
            'extension_event' => TurnStartedEvent::class, 'exception_class' => \RuntimeException::class,
        ]], $logs[0]);
    }
}
