<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\Mercure;

use Ineersa\AgentCore\Api\Serializer\RunEventSerializer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Infrastructure\Mercure\RunEventPublisher;
use Ineersa\AgentCore\Infrastructure\Mercure\RunTopicPolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Update;

final class RunEventPublisherTest extends TestCase
{
    public function testPublisherUsesStage08EnvelopeTopicAndCoalescingPolicy(): void
    {
        $hub = new class implements HubInterface {
            /** @var list<Update> */
            public array $updates = [];

            public function getPublicUrl(): string
            {
                return 'https://mercure.example.test/.well-known/mercure';
            }

            public function getFactory(): ?TokenFactoryInterface
            {
                return null;
            }

            public function publish(Update $update): string
            {
                $this->updates[] = $update;

                return (string) \count($this->updates);
            }
        };

        $publisher = new RunEventPublisher($hub, new RunEventSerializer(), new RunTopicPolicy(), 100);

        $publisher->publish(new RunEvent(
            runId: 'run-mercure-1',
            seq: 1,
            turnNo: 1,
            type: 'message_update',
            payload: ['delta' => 'hello'],
            createdAt: new \DateTimeImmutable('2026-04-12T12:00:00+00:00'),
        ));

        $publisher->publish(new RunEvent(
            runId: 'run-mercure-1',
            seq: 2,
            turnNo: 1,
            type: 'message_update',
            payload: ['delta' => ' world'],
            createdAt: new \DateTimeImmutable('2026-04-12T12:00:00+00:00'),
        ));

        $publisher->publish(new RunEvent(
            runId: 'run-mercure-1',
            seq: 3,
            turnNo: 1,
            type: 'message_end',
            payload: ['text' => 'hello world'],
            createdAt: new \DateTimeImmutable('2026-04-12T12:00:01+00:00'),
        ));

        self::assertCount(2, $hub->updates);

        self::assertSame(['agent/runs/run-mercure-1'], $hub->updates[0]->getTopics());
        self::assertSame('1', $hub->updates[0]->getId());
        self::assertSame('message_update', $hub->updates[0]->getType());

        $firstPayload = json_decode($hub->updates[0]->getData(), true);
        self::assertIsArray($firstPayload);
        self::assertSame('run-mercure-1', $firstPayload['run_id'] ?? null);
        self::assertSame('1.0', $firstPayload['schema_version'] ?? null);
        self::assertArrayHasKey('ts', $firstPayload);
        self::assertArrayNotHasKey('created_at', $firstPayload);

        self::assertSame('3', $hub->updates[1]->getId());
        self::assertSame('message_end', $hub->updates[1]->getType());
    }
}
