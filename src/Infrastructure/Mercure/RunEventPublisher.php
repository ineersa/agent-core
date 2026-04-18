<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Mercure;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final readonly class RunEventPublisher
{
    public function __construct(private ?HubInterface $hub = null)
    {
    }

    public function publish(RunEvent $event): void
    {
        if (null === $this->hub) {
            return;
        }

        $payload = json_encode([
            'run_id' => $event->runId,
            'seq' => $event->seq,
            'turn_no' => $event->turnNo,
            'type' => $event->type,
            'payload' => $event->payload,
            'created_at' => $event->createdAt->format(\DATE_ATOM),
        ]);

        if (false === $payload) {
            return;
        }

        $this->hub->publish(new Update(
            topics: $this->topics($event->runId),
            data: $payload,
            private: true,
        ));
    }

    /**
     * @return list<string>
     */
    private function topics(string $runId): array
    {
        return [
            \sprintf('/agent-loop/runs/%s/events', $runId),
            \sprintf('/agent-loop/runs/%s/stream', $runId),
        ];
    }
}
