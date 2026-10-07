<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\LocalMetadataCoordinator;
use Ineersa\AgentCore\Application\Pipeline\SourceAcceptance;
use Ineersa\AgentCore\Application\Pipeline\TransitionFinalizer;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
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
    ): TransitionFinalizer {
        $commands ??= new InMemoryCommandStore();
        $batches ??= new TestToolBatchStore();
        $operations ??= new TestExecutionOperationStore();
        $sourceAcceptance ??= new SourceAcceptance($commands);
        $transactions = new class implements ApplicationDbTransactionInterface {
            public function transactional(callable $callback): mixed
            {
                return $callback();
            }
        };

        return new TransitionFinalizer(
            $store,
            $dispatcher,
            new LocalMetadataCoordinator(
                $transactions,
                $batches,
                $commands,
                $operations,
                $sourceAcceptance,
            ),
        );
    }
}
