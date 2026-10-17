<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Application\Pipeline\ReceiveToolCompletionHandler;
use Ineersa\AgentCore\Application\Pipeline\TransitionPlanFactory;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCompletionRecipientRepositoryInterface;
use Ineersa\AgentCore\Domain\Message\ReceiveToolCompletion;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Tests\Support\ToolCompletionReceiptFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class ToolCompletionRecipientPersistenceTest extends IsolatedKernelTestCase
{
    #[DataProvider('failurePoints')]
    public function testRecipientAndMailboxRecoverTogether(bool $failMailbox): void
    {
        $container = self::getContainer();
        [$runId, $source, $reference] = ToolCompletionReceiptFixture::create($container);
        $state = new RunState($runId, \Ineersa\AgentCore\Domain\Run\RunStatus::Running, turnNo: 1, lastSeq: $source->seq);
        $result = $container->get(ReceiveToolCompletionHandler::class)->handle(new ReceiveToolCompletion($reference), $state);
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $store->appendTransition($result->events, ['run_id' => $runId, 'predecessor_seq' => $source->seq, 'actions' => $result->postCommitActions]);
        $verified = $store->verifiedPendingTransition($runId);
        $this->assertNotNull($verified);
        $plan = $container->get(TransitionPlanFactory::class)->create($runId, $verified, [], $verified->work['actions']);
        $recipients = $container->get(ToolCompletionRecipientRepositoryInterface::class);
        $commands = $container->get(CommandStoreInterface::class);
        if ($failMailbox) {
            $failing = $this->createMock(CommandStoreInterface::class);
            $failing->expects($this->once())->method('prepareEnqueue')->willReturn(static function () use ($recipients, $reference, $runId): bool {
                self::assertNotNull($recipients->findDelivery($runId, $reference->messageId), 'Recipient INSERT must precede sibling failure.');
                throw new \RuntimeException('injected mailbox failure');
            });
            $coordinator = new LocalMetadataCoordinator($container->get(ApplicationDbTransactionInterface::class), $container->get(ToolBatchStoreInterface::class), $failing, $container->get(DeferredToolCompletionRepositoryInterface::class), $recipients);
            try {
                $coordinator->apply($plan);
                $this->fail('Sibling failure must roll back the whole metadata transaction.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('injected mailbox failure', $exception->getMessage());
            }
            $this->assertNull($recipients->findDelivery($runId, $reference->messageId));
            $this->assertFalse($commands->has($runId, $reference->messageId));
        } else {
            $container->get(LocalMetadataCoordinator::class)->apply($plan);
            $this->assertNotNull($recipients->findDelivery($runId, $reference->messageId));
            $this->assertTrue($commands->has($runId, $reference->messageId));
        }
        $this->assertNotNull($store->verifiedPendingTransition($runId));
        $container->get(RunLockManager::class)->synchronized($runId, static function () use ($container, $runId): void {
            $container->get(PendingTransitionRecovery::class)->recover($runId);
        });
        $this->assertNull($store->verifiedPendingTransition($runId));
        $delivery = $recipients->findDelivery($runId, $reference->messageId);
        $this->assertNotNull($delivery);
        $this->assertSame($verified->eventSequences[0], $delivery->receivedSequence);
        $this->assertEquals($reference, $delivery->reference);
        $this->assertSame(1, $commands->countPending($runId));
    }

    public static function failurePoints(): iterable
    {
        yield 'recipient INSERT rolls back on mailbox failure' => [true];
        yield 'SQL settled but canonical finalization interrupted' => [false];
    }
}
