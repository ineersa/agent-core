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
    public function testDispatchesRegisteredExtensionHook(): void
    {
        $registry = new ExtensionHookRegistry();
        $called = false;
        $registry->addAfterTurnCommitHook(new class($called) implements AfterTurnCommitHookInterface {
            public function __construct(private bool &$called)
            {
            }

            public function onAfterTurnCommit(AfterTurnCommitHookContextDTO $context): void
            {
                $this->called = true;
            }
        });
        $subscriber = new ExtensionAfterTurnCommitHookSubscriber($registry, new TestLogger());
        $ctx = new AfterTurnCommitHookContext('run-1', 2, 'running', [new AfterTurnCommitEventSummary(1, 'turn_end')], 0);
        $subscriber->handleAfterTurnCommit($ctx);
        $this->assertTrue($called);
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
