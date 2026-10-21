<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\MarkCommandAppliedDTO;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use PHPUnit\Framework\TestCase;

final class LocalMetadataCoordinatorTest extends TestCase
{
    public function testVerifiedEmptyPlanSkipsMetadataPreparationAndTransaction(): void
    {
        $transactions = $this->createMock(ApplicationDbTransactionInterface::class);
        $transactions->expects($this->never())->method('transactional');
        $batches = $this->createMock(ToolBatchStoreInterface::class);
        $batches->expects($this->never())->method('prepareChanges');
        $commands = $this->createMock(CommandStoreInterface::class);
        foreach (['prepareEnqueue', 'enqueue', 'markApplied', 'markRejected'] as $method) {
            $commands->expects($this->never())->method($method);
        }

        $coordinator = new LocalMetadataCoordinator($transactions, $batches, $commands);
        $coordinator->apply(new TransitionPlan('run-1', new VerifiedTransitionDTO('verified', []), [], [], []));
    }

    public function testEmptyPlanStillRequiresVerifiedTransition(): void
    {
        $transactions = $this->createMock(ApplicationDbTransactionInterface::class);
        $transactions->expects($this->never())->method('transactional');
        $batches = $this->createMock(ToolBatchStoreInterface::class);
        $batches->expects($this->never())->method('prepareChanges');
        $coordinator = new LocalMetadataCoordinator($transactions, $batches, $this->createStub(CommandStoreInterface::class));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Local metadata requires verified transition evidence.');
        $coordinator->apply(new TransitionPlan('run-1', null, [], [], []));
    }

    public function testMailboxOnlyPlanStillAppliesInsideOneTransaction(): void
    {
        $order = [];
        $verified = new VerifiedTransitionDTO('verified', []);
        $batches = $this->createMock(ToolBatchStoreInterface::class);
        $batches->expects($this->once())->method('prepareChanges')->with([], $this->identicalTo($verified))
            ->willReturn(static function () use (&$order): void {
                $order[] = 'batch_apply';
            });
        $transactions = $this->createMock(ApplicationDbTransactionInterface::class);
        $transactions->expects($this->once())->method('transactional')
            ->willReturnCallback(static function (callable $apply) use (&$order): void {
                $order[] = 'begin';
                $apply();
                $order[] = 'commit';
            });
        $commands = $this->createMock(CommandStoreInterface::class);
        $commands->expects($this->once())->method('markApplied')->with('run-1', 'command-1')
            ->willReturnCallback(static function () use (&$order): void {
                $order[] = 'command_apply';
            });

        $coordinator = new LocalMetadataCoordinator($transactions, $batches, $commands);
        $coordinator->apply(new TransitionPlan('run-1', $verified, [new MarkCommandAppliedDTO('run-1', 'command-1')], [], []));

        $this->assertSame(['begin', 'batch_apply', 'command_apply', 'commit'], $order);
    }
}
