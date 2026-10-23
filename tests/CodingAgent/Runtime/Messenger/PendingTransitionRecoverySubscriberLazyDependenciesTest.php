<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Artifact\OwnedRunIdsProvider;
use Ineersa\CodingAgent\Runtime\Messenger\PendingTransitionRecoverySubscriber;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

/**
 * Proves owner-recovery dependencies stay uninitialized for non-owner transports.
 *
 * @covers \Ineersa\CodingAgent\Runtime\Messenger\PendingTransitionRecoverySubscriber
 */
#[CoversClass(PendingTransitionRecoverySubscriber::class)]
final class PendingTransitionRecoverySubscriberLazyDependenciesTest extends IsolatedKernelTestCase
{
    #[Test]
    public function resolvingSubscriberDoesNotInitializeRecoveryDependencies(): void
    {
        $subscriber = $this->resolveSubscriber();

        $this->assertLazyUninitialized($subscriber, 'transitions', PreparedTransitionEventStoreInterface::class);
        $this->assertLazyUninitialized($subscriber, 'recovery', PendingTransitionRecovery::class);
        $this->assertLazyUninitialized($subscriber, 'locks', RunLockManager::class);
        $this->assertLazyUninitialized($subscriber, 'ownership', OwnedRunIdsProvider::class);
    }

    #[Test]
    public function nonRunControlWorkerStartDoesNotInitializeRecoveryDependencies(): void
    {
        $subscriber = $this->resolveSubscriber();

        $worker = new Worker(['llm' => new InMemoryTransport()], new TestMessageBus());
        $subscriber->onStarted(new WorkerStartedEvent($worker));

        $this->assertLazyUninitialized($subscriber, 'transitions', PreparedTransitionEventStoreInterface::class);
        $this->assertLazyUninitialized($subscriber, 'recovery', PendingTransitionRecovery::class);
        $this->assertLazyUninitialized($subscriber, 'locks', RunLockManager::class);
        $this->assertLazyUninitialized($subscriber, 'ownership', OwnedRunIdsProvider::class);
    }

    private function resolveSubscriber(): PendingTransitionRecoverySubscriber
    {
        $subscriber = self::getContainer()->get(PendingTransitionRecoverySubscriber::class);
        $this->assertInstanceOf(PendingTransitionRecoverySubscriber::class, $subscriber);

        return $subscriber;
    }

    /**
     * @param class-string $expectedType
     */
    private function assertLazyUninitialized(PendingTransitionRecoverySubscriber $subscriber, string $property, string $expectedType): void
    {
        $value = (new \ReflectionProperty(PendingTransitionRecoverySubscriber::class, $property))->getValue($subscriber);
        $this->assertInstanceOf($expectedType, $value);
        $this->assertTrue(
            (new \ReflectionClass($value))->isUninitializedLazyObject($value),
            \sprintf('Expected %s to remain uninitialized for non-owner recovery path.', $property),
        );
    }
}
