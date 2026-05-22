<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Publish;

use Ineersa\AgentCore\Contract\RuntimeEventPublisherInterface;
use Ineersa\AgentCore\Domain\Message\PublishRuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Publishes runtime events to the Messenger agent.publisher.bus.
 *
 * In async (multi-process) mode, this replaces InMemoryRuntimeEventSink
 * as the delivery mechanism for transient streaming deltas. The controller
 * event loop (ASYNC-03) consumes these messages and forwards them to TUI.
 *
 * In sync (in-process) mode, the existing InMemoryRuntimeEventSink path
 * is unchanged — this publisher is only active when async transport is
 * wired and the publisher bus has senders configured.
 */
final readonly class MessengerRuntimeEventPublisher implements RuntimeEventPublisherInterface
{
    public function __construct(
        private MessageBusInterface $publisherBus,
    ) {
    }

    public function publish(string $runId, string $type, int $seq, array $payload = []): void
    {
        $this->publisherBus->dispatch(
            new PublishRuntimeEvent($runId, $type, $seq, $payload),
        );
    }

    /**
     * Convenience wrapper that accepts a typed RuntimeEvent DTO.
     *
     * CodingAgent callers (stream subscribers, mappers) can pass the
     * protocol DTO directly without manually extracting fields.
     */
    public function publishEvent(RuntimeEvent $event): void
    {
        $this->publish($event->runId, $event->type, $event->seq, $event->payload);
    }
}
