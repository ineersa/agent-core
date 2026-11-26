<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\EffectDispatchFailedEvent;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Messenger\EffectDispatchFailureSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class EffectDispatchFailureSubscriberTest extends TestCase
{
    public function testBrokerRejectionProducesUserVisibleNotificationWithPartialAcceptanceCounts(): void
    {
        $sink = new InMemoryRuntimeEventSink();
        $subscriber = new EffectDispatchFailureSubscriber($sink, new StdoutRuntimeEventSink(), consumerStdoutEvents: false);
        $events = new EventDispatcher();
        $events->addListener(EffectDispatchFailedEvent::class, $subscriber->onFailure(...));
        $first = new ExecuteLlmStep('123', 1, 'first', 1, 'first', 'tools');
        $second = new ExecuteLlmStep('123', 1, 'second', 1, 'second', 'tools');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(static function (object $message) use ($first): Envelope {
            if ($message === $first) {
                throw new \RuntimeException('secret transport exception detail');
            }

            return new Envelope($message);
        });
        $dispatcher = new StepDispatcher($bus, $bus, new TestLogger(), $events);
        $dispatcher->dispatchEffects([$first, $second]);
        $notifications = iterator_to_array($sink->drain('123'));
        $this->assertCount(1, $notifications);
        $event = $notifications[0];
        $this->assertSame(RuntimeEventTypeEnum::ModelNotification->value, $event->type);
        $this->assertSame(0, $event->seq);
        $notification = AttributeSerializerValidatorTestFactory::denormalizer()->denormalize($event->payload, ModelNotificationDTO::class);
        $this->assertInstanceOf(ModelNotificationDTO::class, $notification);
        $this->assertSame('error', $notification->severity);
        $this->assertSame(['accepted_dispatches' => 1, 'failed_dispatches' => 1], $notification->metadata);
        $this->assertStringContainsString('accepted 1', $notification->text);
        $this->assertStringContainsString('1 send(s) failed', $notification->text);
        $this->assertStringContainsString('/repair', $notification->text);
        $this->assertStringNotContainsString('secret transport exception detail', $notification->text);
    }
}
