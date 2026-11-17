<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Runtime\Controller\ExtensionSessionDeletedHookSubscriber;
use Ineersa\CodingAgent\Session\Event\SessionDeletedEvent;
use Ineersa\Hatfield\ExtensionApi\DTO\AfterSessionDeletedHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionDeletedHookInterface;
use PHPUnit\Framework\TestCase;

final class ExtensionSessionDeletedHookSubscriberTest extends TestCase
{
    public function testFailuresAreIsolatedAndCapturedIdentityIsPassedToRemainingHooks(): void
    {
        $registry = new ExtensionHookRegistry();
        $first = $this->createMock(AfterSessionDeletedHookInterface::class);
        $first->expects($this->once())->method('onAfterSessionDeleted')->willThrowException(new \RuntimeException('private content'));
        $second = $this->createMock(AfterSessionDeletedHookInterface::class);
        $second->expects($this->once())->method('onAfterSessionDeleted')->with($this->callback(static fn (AfterSessionDeletedHookContextDTO $context): bool => '42' === $context->runId && '/target' === $context->cwd && 'parent' === $context->parentRunId));
        $registry->addSessionDeletedHook($first);
        $registry->addSessionDeletedHook($second);
        $logger = new TestLogger();
        (new ExtensionSessionDeletedHookSubscriber($registry, $logger))->onSessionDeleted(new SessionDeletedEvent('42', '/target', 'parent'));
        $this->assertCount(1, $logger->records);
        $this->assertSame('42', $logger->records[0]['context']['session_id']);
        $this->assertStringNotContainsString('private content', json_encode($logger->records, \JSON_THROW_ON_ERROR));
    }
}
