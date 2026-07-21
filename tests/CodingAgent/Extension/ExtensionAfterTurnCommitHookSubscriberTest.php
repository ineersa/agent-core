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
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: post-commit public hook receives full just-committed SessionEventDTO
 * payload/turnNo/createdAt without event-store reads.
 */
final class ExtensionAfterTurnCommitHookSubscriberTest extends TestCase
{
    public function testDispatchesRegisteredExtensionHookWithFullSessionEventDtos(): void
    {
        $registry = new ExtensionHookRegistry();
        $seen = null;
        $registry->addAfterTurnCommitHook(new class($seen) implements AfterTurnCommitHookInterface {
            public function __construct(private mixed &$seen)
            {
            }

            public function onAfterTurnCommit(AfterTurnCommitHookContextDTO $context): void
            {
                $this->seen = $context;
            }
        });
        $subscriber = new ExtensionAfterTurnCommitHookSubscriber($registry, new TestLogger());
        $createdAt = '2026-07-21T12:00:00+00:00';
        $ctx = new AfterTurnCommitHookContext(
            'run-1',
            2,
            'running',
            [new AfterTurnCommitEventSummary(
                seq: 5,
                type: 'llm_step_completed',
                payload: ['usage' => ['input_tokens' => 3]],
                turnNo: 2,
                createdAt: $createdAt,
            )],
            0,
        );
        $subscriber->handleAfterTurnCommit($ctx);

        $this->assertInstanceOf(AfterTurnCommitHookContextDTO::class, $seen);
        $this->assertCount(1, $seen->events);
        $event = $seen->events[0];
        $this->assertInstanceOf(SessionEventDTO::class, $event);
        $this->assertSame('run-1', $event->runId);
        $this->assertSame(5, $event->seq);
        $this->assertSame(2, $event->turnNo);
        $this->assertSame('llm_step_completed', $event->type);
        $this->assertSame(['usage' => ['input_tokens' => 3]], $event->payload);
        $this->assertEquals(new \DateTimeImmutable($createdAt), $event->createdAt);
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
