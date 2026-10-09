<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Messenger\EffectDispatchFailureSubscriber;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

final class EffectDispatchFailureSubscriberTest extends PerMethodIsolatedKernelTestCase
{
    public function testBrokerRejectionProducesUserVisibleNotificationWithPartialAcceptanceCounts(): void
    {
        $container = self::getContainer();
        $container->set(EffectDispatchFailureSubscriber::class, new EffectDispatchFailureSubscriber($container->get(InMemoryRuntimeEventSink::class), $container->get(StdoutRuntimeEventSink::class), consumerStdoutEvents: false));
        $first = new ExecuteLlmStep('123', 1, 'first', 1, 'first', 'tools');
        $second = new ExecuteLlmStep('123', 1, 'second', 1, 'second', 'tools');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->exactly(2))->method('dispatch')->willReturnCallback(static function (object $message) use ($first): Envelope {
            if ($message === $first) {
                throw new \RuntimeException('secret transport exception detail');
            }

            return new Envelope($message);
        });
        $dispatcher = new StepDispatcher($bus, $bus, new TestLogger(), self::getContainer()->get(EventDispatcherInterface::class));
        $dispatcher->dispatchEffects([$first, $second]);
        $notifications = iterator_to_array(self::getContainer()->get(InMemoryRuntimeEventSink::class)->drain('123'));
        $this->assertCount(1, $notifications);
        $event = $notifications[0];
        $this->assertSame(RuntimeEventTypeEnum::ModelNotification->value, $event->type);
        $this->assertSame(0, $event->seq);
        $notification = self::getContainer()->get(DenormalizerInterface::class)->denormalize($event->payload, ModelNotificationDTO::class);
        $this->assertInstanceOf(ModelNotificationDTO::class, $notification);
        $this->assertSame('error', $notification->severity);
        $this->assertSame(['accepted_dispatches' => 1, 'failed_dispatches' => 1], $notification->metadata);
        $this->assertStringContainsString('accepted 1', $notification->text);
        $this->assertStringContainsString('1 send(s) failed', $notification->text);
        $this->assertStringContainsString('/repair', $notification->text);
        $this->assertStringNotContainsString('secret transport exception detail', $notification->text);
    }
}
