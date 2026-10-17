<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Tool;

use Ineersa\AgentCore\Application\Handler\CoordinationActionValidator;
use Ineersa\AgentCore\Application\Handler\HookDispatcher;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\RunOrchestrator;
use Ineersa\AgentCore\Application\Pipeline\TransitionFinalizer;
use Ineersa\AgentCore\Application\Pipeline\TransitionPlanFactory;
use Ineersa\AgentCore\Application\Tool\ToolCompletionPublicationSubscriber;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCompletionRecipientRepositoryInterface;
use Ineersa\AgentCore\Domain\Coordination\PublishToolCompletionDTO;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Message\ReceiveToolCompletion;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\ToolCompletionReceiptFixture;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ToolCompletionPublicationSubscriberTest extends IsolatedKernelTestCase
{
    public function testAllocatedSourcePublishesOnlyAfterCommitAndReachesLockedRecipientWithoutWaking(): void
    {
        $container = self::getContainer();
        [$runId, $source] = ToolCompletionReceiptFixture::prepare($container);
        $path = $container->get(HatfieldSessionStore::class)->resolveSessionsBasePath().'/'.$runId.'/events.jsonl';
        TestDirectoryIsolation::ensureDirectory(\dirname($path));
        file_put_contents($path, '{"schema_version":"1.0","run_id":"'.$runId.'","seq":99,"turn_no":0,"type":"run_started","payload":[]}'."\n");
        file_put_contents(FileRunSequenceAllocator::counterPathForEventsLog($path), "105\n");
        $state = new RunState($runId, RunStatus::Completed, turnNo: 1, lastSeq: 99);
        $container->get(ActiveRunContextInterface::class)->loadRecovered($state);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $published = [];
        $wasFinalized = false;
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects($this->once())->method('dispatch')->willReturnCallback(static function (object $message) use (&$published, &$wasFinalized, $store, $runId): Envelope {
            $published[] = $message;
            $wasFinalized = null === $store->verifiedPendingTransition($runId) && [] !== iterator_to_array($store->rangeFor($runId, 106, 106));

            return new Envelope($message);
        });
        $dispatcher = new StepDispatcher($bus, $bus, new NullLogger(), new EventDispatcher());
        $locks = $container->get(RunLockManager::class);
        $finalizer = new TransitionFinalizer($store, $dispatcher, $container->get(LocalMetadataCoordinator::class), $container->get(TransitionPlanFactory::class), $locks);
        $commit = new RunCommit($container->get(ActiveRunContextInterface::class), $store, new NullLogger(), $finalizer, new HookDispatcher([$container->get(ToolCompletionPublicationSubscriber::class)]), actionValidator: $container->get(CoordinationActionValidator::class));
        $locks->synchronized($runId, static function () use ($commit, $state, $source): void {
            $commit->commit($state, $state, [$source]);
        });
        $this->assertTrue($wasFinalized);
        $this->assertCount(1, $published);
        $delivery = $published[0];
        $this->assertInstanceOf(ReceiveToolCompletion::class, $delivery);
        $this->assertSame(106, $delivery->reference->sourceEvent->seq);
        $this->assertNull($container->get(ToolCompletionRecipientRepositoryInterface::class)->findDelivery($runId, $delivery->reference->messageId));
        $container->get(RunOrchestrator::class)->onReceiveToolCompletion($delivery);
        $recipient = $container->get(ToolCompletionRecipientRepositoryInterface::class)->findDelivery($runId, $delivery->reference->messageId);
        $this->assertNotNull($recipient);
        $this->assertSame(107, $recipient->receivedSequence);
        $this->assertSame(106, $recipient->reference->sourceEvent->seq);
        $this->assertCount(1, $container->get(CommandStoreInterface::class)->pending($runId));
        $this->assertSame(RunStatus::Completed, $container->get(ActiveRunContextInterface::class)->requireLoaded($runId)->status);
        $this->assertCount(1, $published, 'Admission must not schedule unsafe continuation.');
    }

    public function testForegroundReceiptHasNoPublicationAndConfiguredHookCapturesDetachedReceipt(): void
    {
        $container = self::getContainer();
        [$runId, $event] = ToolCompletionReceiptFixture::prepare($container);
        $state = new RunState($runId, RunStatus::Completed);
        $context = AfterTurnCommitHookContext::fromRunState($state, [$event], 0);
        $actions = $container->get(HookDispatcher::class)->prepareAfterTurnCommit($context, 0);
        $this->assertTrue(array_any($actions, static fn (object $action): bool => $action instanceof PublishToolCompletionDTO));
        $payload = $event->payload;
        $payload['accepted_completion']['detached'] = false;
        $foreground = new \Ineersa\AgentCore\Domain\Event\RunEvent($runId, 1, 1, $event->type, $payload);
        $this->assertSame([], $container->get(ToolCompletionPublicationSubscriber::class)->prepareAfterTurnCommit(AfterTurnCommitHookContext::fromRunState($state, [$foreground], 0), 0));
    }
}
