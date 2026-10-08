<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\DurablePendingPublication;
use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Application\Pipeline\SourceAcceptance;
use Ineersa\AgentCore\Application\Pipeline\TransitionFinalizer;
use Ineersa\AgentCore\Application\Pipeline\TransitionPlanFactory;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\ControlMessageOutboxInterface;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\MessageBusInterface;

/** Builds the configured TransitionFinalizer for unit fixtures without a kernel. */
final class TestTransitionFinalizerFactory
{
    public static function create(
        PreparedTransitionEventStoreInterface $store,
        StepDispatcher $dispatcher,
        ?ToolBatchStoreInterface $batches = null,
        ?CommandStoreInterface $commands = null,
        ?ExecutionOperationStoreInterface $operations = null,
        ?SourceAcceptance $sourceAcceptance = null,
        ?MessageBusInterface $commandBus = null,
        ?MessageBusInterface $executionBus = null,
        ?RunLockManager $locks = null,
        ?ControlMessageOutboxInterface $outbox = null,
    ): TransitionFinalizer {
        $commands ??= new InMemoryCommandStore();
        $batches ??= new TestToolBatchStore();
        $operations ??= new TestExecutionOperationStore();
        $sourceAcceptance ??= new SourceAcceptance($commands);
        $commandBus ??= new TestMessageBus();
        $executionBus ??= new TestMessageBus();
        $locks ??= new RunLockManager(new LockFactory(new InMemoryStore()));
        $outbox ??= new InMemoryControlMessageOutbox();
        $transactions = new class implements ApplicationDbTransactionInterface {
            public function transactional(callable $callback): mixed
            {
                return $callback();
            }
        };
        $publication = new DurablePendingPublication(
            $store,
            $operations,
            $outbox,
            $locks,
            $commandBus,
            $executionBus,
            new TestLogger(),
        );

        return new TransitionFinalizer(
            $store,
            $dispatcher,
            new LocalMetadataCoordinator(
                $transactions,
                $batches,
                $commands,
                $operations,
                $sourceAcceptance,
                $publication,
            ),
            new TransitionPlanFactory(),
            $publication,
        );
    }
}
