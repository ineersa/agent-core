<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\EffectDispatchFailedEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/** Shows broker rejection through the existing transient notification flow. */
final readonly class EffectDispatchFailureSubscriber
{
    public function __construct(
        private InMemoryRuntimeEventSink $sink,
        private StdoutRuntimeEventSink $stdoutSink,
        #[Autowire('%env(bool:HATFIELD_CONSUMER_STDOUT_EVENTS)%')]
        private bool $consumerStdoutEvents,
    ) {
    }

    #[AsEventListener]
    public function onFailure(EffectDispatchFailedEvent $failure): void
    {
        $event = new RuntimeEvent(RuntimeEventTypeEnum::ModelNotification->value, $failure->runId, 0, [
            'id' => Uuid::v7()->toRfc4122(),
            'source' => 'runtime',
            'kind' => 'dispatch_failed',
            'severity' => 'error',
            'delivery' => 'transcript',
            'text' => \sprintf('Dispatch failed: Messenger accepted %d request(s); %d send(s) failed. Some work may still run. Use /repair explicitly to retry; external effects may repeat.', $failure->accepted, $failure->failed),
            'metadata' => ['accepted_dispatches' => $failure->accepted, 'failed_dispatches' => $failure->failed],
        ]);
        if ($this->consumerStdoutEvents) {
            $this->stdoutSink->emit($event);
        } else {
            $this->sink->emit($event);
        }
    }
}
