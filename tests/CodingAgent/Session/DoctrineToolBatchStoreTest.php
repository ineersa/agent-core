<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Coordination\EnqueueCommandDTO;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO;
use Ineersa\AgentCore\Domain\Coordination\TransitionPlan;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class DoctrineToolBatchStoreTest extends IsolatedKernelTestCase
{
    public function testQueuedCallWaitsForCapacityAndMetadataRollbackIsAtomic(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('sql scheduling');
        $first = new ExecuteToolCall($run, 1, 'tools', 1, 'key-a', 'call-a', 'echo', ['command' => 'a'], 0);
        $second = new ExecuteToolCall($run, 1, 'tools', 1, 'key-b', 'call-b', 'echo', ['command' => 'b'], 1);
        $register = new RegisterToolBatchDTO($run, 1, 'tools', [$first, $second], ['call-a' => 0, 'call-b' => 1], ['call-b'], ['call-a' => true], 1);
        $mailbox = new EnqueueCommandDTO(new PendingCommand($run, 'follow_up', 'mailbox-admit', ['text' => 'keep']));
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $batches = $container->get(ToolBatchStoreInterface::class);
        $commands = $container->get(CommandStoreInterface::class);
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => [$register, $mailbox]]);
        $pending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $plan = new TransitionPlan($run, $pending, [$register, $mailbox], [], []);
        $failingCommands = $this->createStub(CommandStoreInterface::class);
        $failingCommands->method('prepareEnqueue')->willReturn(static function (): bool {
            throw new \RuntimeException('Injected mailbox failure.');
        });
        $coordinator = new LocalMetadataCoordinator($container->get(ApplicationDbTransactionInterface::class), $batches, $failingCommands);
        try {
            $coordinator->apply($plan);
            $this->fail('Mailbox failure must roll back batch registration.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected mailbox failure.', $exception->getMessage());
        }
        $this->assertNull($batches->load($run, 1, 'tools'));
        $this->assertFalse($commands->has($run, 'mailbox-admit'));
        $this->assertNotNull($events->verifiedPendingTransition($run));

        $coordinator = $container->get(LocalMetadataCoordinator::class);
        $coordinator->apply($plan);
        $events->finalizeVerifiedTransition($run, $pending->identity);
        $batch = $batches->load($run, 1, 'tools');
        $this->assertNotNull($batch);
        $this->assertEquals(['call-a' => $first, 'call-b' => $second], $batch->calls);
        $this->assertSame(['call-a' => true], $batch->inFlight);
        $this->assertSame(['call-b'], $batch->pendingQueue);
        $this->assertTrue($commands->has($run, 'mailbox-admit'));

        $complete = new FinalizeToolBatchDTO($run, 1, 'tools', pendingQueue: [], inFlight: ['call-b' => true], awaitingHumanInput: [], finalized: false);
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'actions' => [$complete]]);
        $next = $events->verifiedPendingTransition($run);
        $this->assertNotNull($next);
        $coordinator->apply(new TransitionPlan($run, $next, [$complete], [], []));
        $events->finalizeVerifiedTransition($run, $next->identity);
        $batch = $batches->load($run, 1, 'tools');
        $this->assertNotNull($batch);
        $this->assertSame([], $batch->pendingQueue);
        $this->assertSame(['call-b' => true], $batch->inFlight);
        $this->assertEquals($second, $batch->calls['call-b']);
    }
}
