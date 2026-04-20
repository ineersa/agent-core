<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Mercure;

use Ineersa\AgentCore\Api\Serializer\RunEventSerializer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * The RunEventPublisher class publishes domain RunEvent instances to a Mercure hub using configurable serialization and topic policies. It implements a coalescing mechanism to batch rapid updates within a defined time window, reducing unnecessary network traffic.
 */
final class RunEventPublisher
{
    /** @var array<string, int> */
    private array $lastMessageUpdatePublishedAtNsByRun = [];

    public function __construct(
        private ?HubInterface $hub = null,
        private ?RunEventSerializer $serializer = null,
        private ?RunTopicPolicy $topicPolicy = null,
        private int $messageUpdateCoalesceWindowMs = 75,
    ) {
    }

    public function publish(RunEvent $event): void
    {
        if (null === $this->hub) {
            return;
        }

        if ($this->shouldCoalesce($event)) {
            return;
        }

        $serializer = $this->serializer ?? new RunEventSerializer();
        $payload = json_encode($serializer->normalizeRunEvent($event));

        if (false === $payload) {
            return;
        }

        $topicPolicy = $this->topicPolicy ?? new RunTopicPolicy();

        $this->hub->publish(new Update(
            topics: $topicPolicy->topicsFor($event->runId),
            data: $payload,
            private: true,
            id: (string) $event->seq,
            type: $event->type,
        ));
    }

    private function shouldCoalesce(RunEvent $event): bool
    {
        if ('message_update' !== $event->type) {
            return false;
        }

        $now = hrtime(true);
        $lastPublishedAt = $this->lastMessageUpdatePublishedAtNsByRun[$event->runId] ?? null;
        $this->lastMessageUpdatePublishedAtNsByRun[$event->runId] = $now;

        if (null === $lastPublishedAt) {
            return false;
        }

        $windowNs = $this->messageUpdateCoalesceWindowMs * 1_000_000;

        return ($now - $lastPublishedAt) < $windowNs;
    }
}
