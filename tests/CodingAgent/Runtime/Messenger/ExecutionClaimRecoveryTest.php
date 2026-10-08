<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Messenger;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\ExecutionOutcomeUnknownHandler;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ConsumeExecutionUnknownDTO;
use Ineersa\AgentCore\Domain\Coordination\ExecutionAuthorizationStamp;
use Ineersa\AgentCore\Domain\Message\DurableExecutionResult;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecutionOutcomeUnknown;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
use Ineersa\CodingAgent\Session\DoctrineExecutionOperationStore;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

final class ExecutionClaimRecoveryTest extends IsolatedKernelTestCase
{
    public static function claims(): iterable
    {
        foreach (['llm', 'compaction', 'shell'] as $kind) {
            foreach (['missing', 'seal', 'corrupt'] as $mode) {
                yield $kind.' '.$mode => [$kind, $mode];
            }
        }
    }

    #[DataProvider('claims')]
    public function testLiveExclusionThenDeadClaimDecisionUsesOriginalReceipt(string $kind, string $mode): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('claim recovery');
        $directory = TestDirectoryIsolation::createProjectTempDir('execution-claim-database');
        $database = $directory.'/state.sqlite';
        $input = new InputStream();
        $process = new Process([\PHP_BINARY, __DIR__.'/Support/ExecutionClaimWorker.php', getcwd(), $database, $run, $kind, $mode], env: ['HATFIELD_SESSION_ID' => false]);
        $process->setInput($input);
        $process->setTimeout(5);
        $connection = null;
        try {
            $process->start();
            $ready = '';
            $this->assertTrue($process->waitUntil(static function (string $type, string $output) use (&$ready): bool {
                if (Process::OUT === $type) {
                    $ready .= $output;
                }

                return str_contains($ready, "claim_ready\n");
            }), $process->getErrorOutput());
            $this->assertTrue($process->isRunning(), 'Readiness must belong to a live worker holding its ownership lock.');
            $connection = $container->get('doctrine.dbal.connection_factory')->createConnection(['driver' => 'pdo_sqlite', 'path' => $database]);
            $store = $this->store($connection);
            $record = $connection->fetchAssociative('SELECT * FROM execution_operation WHERE run_id = ?', [$run]);
            $this->assertIsArray($record);
            $this->assertSame('Running', $record['state']);
            $reference = new ExecutionRequest($run, (int) $record['turn_no'], $record['step_id'], (int) $record['attempt'], $record['idempotency_key'], $record['effect_id'], $record['request_type'], $record['request_hash'], (int) $record['request_bytes']);
            $stamp = new ExecutionAuthorizationStamp($reference->effectId, $reference->sha256);
            $this->assertNull($store->claim($reference, $stamp));
            $this->assertSame([$reference->effectId => null], $store->pendingDeliveries($run, ''), 'Even a sealed file cannot be adopted while the original worker is live.');
            $input->write("finish\n");
            $input->close();
            $this->assertSame(0, $process->wait(), $process->getErrorOutput());
            // This new store is another process-instance identity, not the old receipt owner.
            $store = $this->store($connection);
            if ('corrupt' === $mode) {
                $deliveries = $store->pendingDeliveries($run, '');
                $this->assertSame([$reference->effectId => null], $deliveries, 'Corrupt evidence must not become success or OutcomeUnknown.');
                $this->assertSame('Running', $connection->fetchOne('SELECT state FROM execution_operation WHERE effect_id = ?', [$reference->effectId]));
                $this->assertSame([$reference->effectId => null], $this->store($connection)->pendingDeliveries($run, ''), 'Later sweeps revisit the same failed identity.');

                return;
            }
            $deliveries = $store->pendingDeliveries($run, '');
            $this->assertCount(1, $deliveries);
            $envelope = $deliveries[$reference->effectId];
            $this->assertInstanceOf(Envelope::class, $envelope);
            $message = $envelope->getMessage();
            if ('seal' === $mode) {
                $this->assertInstanceOf(DurableExecutionResult::class, $message);
                $this->assertSame($record['claim_token'], $message->claimToken);
                $this->assertSame($reference->stepId(), $store->resolveResult($message)->stepId());
                $this->assertEquals($message, $store->claim($reference, $stamp));
                $this->assertEquals($deliveries, $this->store($connection)->pendingDeliveries($run, ''), 'Lost notifications repeat the original adopted result.');
            } else {
                $this->assertInstanceOf(ExecutionOutcomeUnknown::class, $message);
                $this->assertSame($record['claim_token'], $message->claimToken);
                $this->assertNull($store->claim($reference, $stamp));
                $this->assertEquals($deliveries, $this->store($connection)->pendingDeliveries($run, ''), 'An uncommitted unknown notice remains discoverable.');
                $this->consumeUnknown($store, $message);
                $this->assertSame([], $this->store($connection)->pendingDeliveries($run, ''), 'Only the verified owner commit retires the notice.');
                try {
                    $store->assertNoUnknownExecution($run);
                    $this->fail('A committed notice must not reauthorize unknown work.');
                } catch (\RuntimeException $exception) {
                    $this->assertSame(ExecutionOutcomeUnknown::ERROR_MESSAGE, $exception->getMessage());
                }
                $events = $container->get(PreparedTransitionEventStoreInterface::class);
                $next = new ExecuteLlmStep($run, 2, 'later', 1, 'later-key', 'tools');
                $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 1, 'effects' => [$next]]);
                $transition = $events->verifiedPendingTransition($run);
                $this->assertNotNull($transition);
                $store->prepare($next, $transition);
                $authorization = $store->arm($next, $transition);
                $events->finalizeVerifiedTransition($run, $transition->identity);
                $this->assertNull($store->claim($store->requestReference($next, $authorization), $authorization), 'Unknown side effects must block another armed invocation.');
                $this->assertSame('Armed', $connection->fetchOne('SELECT state FROM execution_operation WHERE effect_id = ?', [$authorization->effectId]));
            }
            $this->assertSame('one invocation', file_get_contents(getcwd().'/invocation-'.$kind.'.txt'));
        } finally {
            // No signals: EOF releases the pipe barrier even on assertion failure.
            $input->close();
            if ($process->isRunning()) {
                $process->wait();
            }
            $connection?->close();
            $container->get(ActiveRunContextInterface::class)->release($run);
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    private function store(Connection $connection): DoctrineExecutionOperationStore
    {
        $container = self::getContainer();

        return new DoctrineExecutionOperationStore($connection, $container->get(ToolBatchRunStoragePathsInterface::class), new Filesystem(), $container->get('hatfield.controller.session_owner.lock_factory'), $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class), $container->get(PreparedTransitionEventStoreInterface::class), $container->get(\Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface::class));
    }

    private function consumeUnknown(DoctrineExecutionOperationStore $store, ExecutionOutcomeUnknown $notice): void
    {
        $container = self::getContainer();
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $active = $container->get(ActiveRunContextInterface::class);
        $active->createNew($notice->runId());
        $state = RunState::queued($notice->runId())->with(['status' => RunStatus::Running, 'turnNo' => $notice->turnNo(), 'activeStepId' => $notice->stepId(), 'currentOperation' => new CurrentOperationDTO($notice->turnNo(), $notice->stepId(), $notice->attempt(), $notice->idempotencyKey())]);
        $active->replaceCurrent($state);
        $result = (new ExecutionOutcomeUnknownHandler($store, $container->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class)))->handle($notice, $state);
        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Failed, $result->nextState->status);
        $this->assertSame(ExecutionOutcomeUnknown::ERROR_MESSAGE, $result->nextState->errorMessage);
        $interrupt = true;
        $operations = new class($store, $interrupt) implements \Ineersa\AgentCore\Contract\ExecutionOperationStoreInterface {
            public function __construct(private DoctrineExecutionOperationStore $inner, private bool &$interrupt)
            {
            }

            public function unknownExecutionsForRepair(string $runId): array
            {
                return $this->inner->unknownExecutionsForRepair($runId);
            }

            public function assertUnknownRepairable(ExecutionOutcomeUnknown $notice): void
            {
                $this->inner->assertUnknownRepairable($notice);
            }

            public function matchesCurrentAuthorizedToolInvocation(ExecutionOutcomeUnknown $notice, \Ineersa\AgentCore\Domain\Message\ExecuteToolCall $authorizedCall): bool
            {
                return $this->inner->matchesCurrentAuthorizedToolInvocation($notice, $authorizedCall);
            }

            public function repairDelivery(string $runId, CurrentOperationDTO $operation, string $requestType): ?Envelope
            {
                return $this->inner->repairDelivery($runId, $operation, $requestType);
            }

            public function resolveRequest(ExecutionRequest $reference, ExecutionAuthorizationStamp $authorization, string $claim): \Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage
            {
                return $this->inner->resolveRequest($reference, $authorization, $claim);
            }

            public function resultForClaim(ExecutionRequest $reference, ExecutionAuthorizationStamp $authorization, string $claim): DurableExecutionResult
            {
                return $this->inner->resultForClaim($reference, $authorization, $claim);
            }

            public function assertRequestCapacity(\Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $request): void
            {
                $this->inner->assertRequestCapacity($request);
            }

            public function prepare(\Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $request, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): ExecutionAuthorizationStamp
            {
                return $this->inner->prepare($request, $transition);
            }

            public function arm(\Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $request, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): ExecutionAuthorizationStamp
            {
                return $this->inner->arm($request, $transition);
            }

            public function requestReference(\Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $request, ExecutionAuthorizationStamp $authorization): ExecutionRequest
            {
                return $this->inner->requestReference($request, $authorization);
            }

            public function pendingDeliveries(string $ownerSessionId, string $afterEffectId): array
            {
                return $this->inner->pendingDeliveries($ownerSessionId, $afterEffectId);
            }

            public function pendingDeliveriesForRun(string $runId): array
            {
                return $this->inner->pendingDeliveriesForRun($runId);
            }

            public function claim(ExecutionRequest $reference, ExecutionAuthorizationStamp $authorization): string|DurableExecutionResult|null
            {
                return $this->inner->claim($reference, $authorization);
            }

            public function peekRequest(ExecutionRequest $reference): \Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage
            {
                return $this->inner->peekRequest($reference);
            }

            public function transferToDeferred(ExecutionRequest $reference, ExecutionAuthorizationStamp $authorization, string $claim, string $deferredId): void
            {
                $this->inner->transferToDeferred($reference, $authorization, $claim, $deferredId);
            }

            public function saveDeferredResult(string $deferredId, \Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $result): DurableExecutionResult
            {
                return $this->inner->saveDeferredResult($deferredId, $result);
            }

            public function saveResult(\Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $request, ExecutionAuthorizationStamp $authorization, string $claim, \Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage $result): DurableExecutionResult
            {
                return $this->inner->saveResult($request, $authorization, $claim, $result);
            }

            public function resolveResult(DurableExecutionResult $reference): \Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage
            {
                return $this->inner->resolveResult($reference);
            }

            public function isDisposed(DurableExecutionResult $reference): bool
            {
                return $this->inner->isDisposed($reference);
            }

            public function validateDisposition(\Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO $descriptor, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
            {
                $this->inner->validateDisposition($descriptor, $transition);
            }

            public function applyDisposition(\Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO $descriptor, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
            {
                $this->inner->applyDisposition($descriptor, $transition);
            }

            public function reclaimDisposedPayloads(string $ownerSessionId, string $afterEffectId): string
            {
                return $this->inner->reclaimDisposedPayloads($ownerSessionId, $afterEffectId);
            }

            public function unknownNoticePending(ExecutionOutcomeUnknown $notice): bool
            {
                return $this->inner->unknownNoticePending($notice);
            }

            public function consumeUnknownNotice(ConsumeExecutionUnknownDTO $action, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
            {
                if ($this->interrupt) {
                    $this->interrupt = false;
                    throw new \RuntimeException('Injected interruption before unknown notice acknowledgement.');
                }
                $this->inner->consumeUnknownNotice($action, $transition);
            }

            public function assertNoUnknownExecution(string $runId): void
            {
                $this->inner->assertNoUnknownExecution($runId);
            }

            public function retireUnstartedPermissions(string $runId, int $turnNo, string $stepId, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
            {
                $this->inner->retireUnstartedPermissions($runId, $turnNo, $stepId, $transition);
            }

            public function retireUnknownExecution(\Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO $action, \Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO $transition): void
            {
                $this->inner->retireUnknownExecution($action, $transition);
            }
        };
        $commit = new RunCommit(
            activeRunContext: $active,
            eventStore: $events,
            logger: new TestLogger(),
            executionOperations: $operations,
            sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
            finalizer: TestTransitionFinalizerFactory::create($events, new StepDispatcher(new TestMessageBus()), operations: $operations),
        );
        try {
            $commit->commit($state, $result->nextState, $result->events, dispatchAfterTurnHooks: false, postCommitActions: $result->postCommitActions);
            $this->fail('The coordination interruption must leave a recoverable owner decision.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Injected interruption before unknown notice acknowledgement.', $exception->getMessage());
        }
        $this->assertTrue($store->unknownNoticePending($notice));
        $this->assertNotNull($events->verifiedPendingTransition($notice->runId()));
        $recovery = new PendingTransitionRecovery($events, $active, new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()), TestTransitionFinalizerFactory::create($events, new StepDispatcher(new TestMessageBus()), operations: $store));
        $recovery->recover($notice->runId());
        $recovery->recover($notice->runId());
        $this->assertFalse($store->unknownNoticePending($notice));
        $this->assertSame(1, $events->latestSequenceFor($notice->runId()));
        $this->assertNull($events->verifiedPendingTransition($notice->runId()));
        $this->assertSame([], (new ExecutionOutcomeUnknownHandler($store, $container->get(\Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface::class)))->handle($notice, $result->nextState)->events);
    }
}
