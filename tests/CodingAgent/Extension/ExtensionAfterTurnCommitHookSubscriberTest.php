<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitEventSummary;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
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
            public function __construct(private bool &$called) {}
            public function onAfterTurnCommit(AfterTurnCommitHookContextDTO $context): void { $this->called = true; }
        });
        $subscriber = new ExtensionAfterTurnCommitHookSubscriber($registry);
        $ctx = new AfterTurnCommitHookContext('run-1', 2, 'running', [new AfterTurnCommitEventSummary(1, 'turn_end')], 0);
        $subscriber->handleAfterTurnCommit($ctx);
        self::assertTrue($called);
    }
}
