<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Messenger;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Messenger\ExecutionResultMiddleware;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Application\Pipeline\SourceAcceptance;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Tests\Support\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\StackMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class ExecutionResultMiddlewareDisposedRecoveryTest extends IsolatedKernelTestCase
{
    public function testDisposedDuplicateReconcilesUnfinishedJournalBeforeAck(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('disposed unfinished journal');
        $request = new ExecuteLlmStep($run, 1, 'execution', 1, 'original-request', 'tools');
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
        $operations = $container->get(ExecutionOperationStoreInterface::class);
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $operations->prepare($request, $pending);
        $authorization = $operations->arm($request, $pending);
        $store->finalizeVerifiedTransition($run, $pending->identity);
        $delivery = $operations->requestReference($request, $authorization);
        $claim = $operations->claim($delivery, $authorization);
        $this->assertIsString($claim);
        $result = new LlmStepResult($run, 1, 'execution', 1, 'original-request');
        $reference = $operations->saveResult($request, $authorization, $claim, $result);
        $descriptor = new ExecutionResultDispositionDTO($reference, 'Consumed');
        $store->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'execution_disposition' => $descriptor]);
        $this->assertFalse($operations->isDisposed($reference));
        $this->assertNotNull($store->verifiedPendingTransition($run));

        $acceptance = new SourceAcceptance(new InMemoryCommandStore());
        $bus = new TestMessageBus();
        $recovery = new PendingTransitionRecovery(
            $store,
            $container->get(ActiveRunContextInterface::class),
            $acceptance,
            TestTransitionFinalizerFactory::create($store, new StepDispatcher($bus), operations: $operations, commandBus: $bus, executionBus: $bus),
            $container->get(\Ineersa\AgentCore\Application\Handler\CoordinationActionValidator::class),
        );
        $middleware = new ExecutionResultMiddleware($operations, $recovery, new RunLockManager(new LockFactory(new InMemoryStore())));
        $envelope = new Envelope($reference, [new ReceivedStamp('run_control')]);
        $handled = $middleware->handle($envelope, new StackMiddleware());

        $this->assertNull($store->verifiedPendingTransition($run));
        $this->assertTrue($operations->isDisposed($reference));
        $this->assertNotNull($handled->last(HandledStamp::class));
        $this->assertSame([], $bus->messages);
    }
}
