<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Application\Pipeline\TransitionFinalizer;
use Ineersa\AgentCore\Application\Pipeline\TransitionPlanFactory;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/** Builds the real finalizer with in-memory metadata stores for unit fixtures. */
final class TestTransitionFinalizerFactory
{
    public static function create(
        PreparedTransitionEventStoreInterface $store,
        StepDispatcher $dispatcher,
        ?ToolBatchStoreInterface $batches = null,
        ?CommandStoreInterface $commands = null,
        ?RunLockManager $locks = null,
    ): TransitionFinalizer {
        $transactions = new class implements ApplicationDbTransactionInterface {
            public function transactional(callable $callback): mixed
            {
                return $callback();
            }
        };

        return new TransitionFinalizer(
            $store,
            $dispatcher,
            new LocalMetadataCoordinator($transactions, $batches ?? new TestToolBatchStore(), $commands ?? new InMemoryCommandStore()),
            new TransitionPlanFactory(),
            $locks ?? new RunLockManager(new LockFactory(new InMemoryStore())),
        );
    }
}
