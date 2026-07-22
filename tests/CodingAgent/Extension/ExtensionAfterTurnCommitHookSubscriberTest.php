<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Extension\ExtensionAfterTurnCommitHookSubscriber;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookInterface;
use PHPUnit\Framework\TestCase;

final class ExtensionAfterTurnCommitHookSubscriberTest extends TestCase
{
    public function testDispatchesRegisteredExtensionHookWithHotBatchPayload(): void
    {
        $registry = new ExtensionHookRegistry();
        $captured = null;
        $registry->addAfterTurnCommitHook(new class($captured) implements AfterTurnCommitHookInterface {
            public function __construct(private mixed &$captured)
            {
            }

            public function onAfterTurnCommit(AfterTurnCommitHookContextDTO $context): void
            {
                $this->captured = $context;
            }
        });
        $subscriber = new ExtensionAfterTurnCommitHookSubscriber($registry, new TestLogger());
        $ctx = new AfterTurnCommitHookContext(
            'run-1',
            2,
            'running',
            [new AfterTurnCommitEventSummary(
                seq: 1,
                type: 'turn_end',
                payload: ['reason' => 'completed'],
                turnNo: 7,
                createdAt: '2026-07-22T12:00:00+00:00',
            )],
            0,
        );
        $subscriber->handleAfterTurnCommit($ctx);
        $this->assertInstanceOf(AfterTurnCommitHookContextDTO::class, $captured);
        $this->assertSame(1, $captured->events[0]->seq);
        $this->assertSame('turn_end', $captured->events[0]->type);
        $this->assertSame(['reason' => 'completed'], $captured->events[0]->payload);
        // Per-event provenance, not the surrounding context turnNo.
        $this->assertSame(7, $captured->events[0]->turnNo);
        $this->assertSame('2026-07-22T12:00:00+00:00', $captured->events[0]->createdAt);
        $this->assertSame(2, $captured->turnNo);
    }

    public function testHookFailureIsLoggedAndDoesNotPropagate(): void
    {
        $registry = new ExtensionHookRegistry();
        $registry->addAfterTurnCommitHook(new class implements AfterTurnCommitHookInterface {
            public function onAfterTurnCommit(AfterTurnCommitHookContextDTO $context): void
            {
                throw new \RuntimeException('boom');
            }
        });
        $logger = new TestLogger();
        $subscriber = new ExtensionAfterTurnCommitHookSubscriber($registry, $logger);
        $ctx = new AfterTurnCommitHookContext('run-9', 3, 'running', [new AfterTurnCommitEventSummary(1, 'turn_end')], 0);
        $subscriber->handleAfterTurnCommit($ctx);
        $this->assertNotEmpty($logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame('extension.after_turn_commit_hook_failed', $logger->records[0]['message']);
    }
}
