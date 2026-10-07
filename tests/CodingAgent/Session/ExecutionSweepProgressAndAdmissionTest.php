<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\SourceAcceptance;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutionAuthorizationInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecutionRequest;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactEntryDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathsDTO;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\Runtime\Messenger\ExecutionPendingDeliverySubscriber;
use Ineersa\CodingAgent\Session\DoctrineExecutionOperationStore;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class ExecutionSweepProgressAndAdmissionTest extends IsolatedKernelTestCase
{
    public function testCorruptPrecedingRunningRowDoesNotStarveHealthyOwnedDelivery(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('corrupt then healthy sweep');
        $first = new ExecuteLlmStep($run, 1, 'first', 1, 'first-key', 'tools');
        $second = new ExecuteLlmStep($run, 1, 'second', 1, 'second-key', 'tools');
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $operations = $container->get(DoctrineExecutionOperationStore::class);
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$first, $second]]);
        $pending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $firstAuth = $operations->arm($first, $pending);
        $secondAuth = $operations->arm($second, $pending);
        $events->finalizeVerifiedTransition($run, $pending->identity);
        $ordered = [$firstAuth->effectId, $secondAuth->effectId];
        sort($ordered);
        [$failedId, $healthyId] = $ordered;
        $connection = $container->get(\Doctrine\DBAL\Connection::class);
        $claim = str_repeat('a', 64).'.dead';
        $connection->executeStatement(
            "UPDATE execution_operation SET state = 'Running', claim_token = ?, worker_instance = ?, claim_lock_key = ? WHERE effect_id = ?",
            [$claim, str_repeat('a', 64), str_repeat('a', 64), $failedId],
        );
        $paths = $container->get(ToolBatchRunStoragePathsInterface::class);
        $directory = \dirname($paths->resolveToolBatchesDirectory($run)).'/execution-operations/'.$failedId;
        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        file_put_contents($directory.'/'.hash('sha256', $claim).'.result', '{not-json');
        $command = new TestMessageBus();
        $execution = new TestMessageBus();
        $logger = new TestLogger();
        $store = new DoctrineExecutionOperationStore(
            $connection,
            $paths,
            new \Symfony\Component\Filesystem\Filesystem(),
            $container->get('hatfield.controller.session_owner.lock_factory'),
            $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class),
            $events,
            $container->get(\Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface::class),
            $logger,
        );
        $subscriber = new ExecutionPendingDeliverySubscriber($store, $events, self::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class), self::getContainer()->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class), self::getContainer()->get(\Doctrine\DBAL\Connection::class), $command, $execution, $run, new TestLogger());
        $subscriber->onStarted(new WorkerStartedEvent(new Worker(['run_control' => new InMemoryTransport()], new TestMessageBus())));
        $this->assertCount(1, $execution->messages);
        $message = $execution->messages[0]->getMessage();
        $this->assertInstanceOf(ExecutionRequest::class, $message);
        $this->assertSame($healthyId, $message->effectId);
        $this->assertSame('Running', $connection->fetchOne('SELECT state FROM execution_operation WHERE effect_id = ?', [$failedId]));
        $this->assertNotSame([], array_filter(
            $logger->records,
            static fn (array $record): bool => 'execution.pending_delivery_record_failed' === $record['message'],
        ));
        $again = $store->pendingDeliveries($run, '');
        $this->assertArrayHasKey($failedId, $again);
        $this->assertNull($again[$failedId]);
        $this->assertInstanceOf(\Symfony\Component\Messenger\Envelope::class, $again[$healthyId]);
    }

    public function testUnfinishedParentCleanupDoesNotStarveFinalizedChildReclaim(): void
    {
        $container = self::getContainer();
        $parent = $container->get(HatfieldSessionStore::class)->createSession('parent cleanup starve');
        $child = '123e4567-e89b-12d3-a456-426614174010';
        $container->get(AgentChildRunDirectory::class)->register(new AgentArtifactEntryDTO(
            artifactId: 'agent_cleanup_child',
            parentRunId: $parent,
            agentRunId: $child,
            agentName: 'scout',
            kind: AgentArtifactKindEnum::Subagent,
            status: AgentArtifactStatusEnum::Pending,
            paths: AgentArtifactPathsDTO::forArtifactId('agent_cleanup_child'),
            createdAt: new \DateTimeImmutable(),
        ));
        $container->get(DeferredSubagentBatchRepository::class)->reserveBatch(
            $child,
            $parent,
            1,
            'launch-cleanup',
            0,
            ChildRunBatchExecutionModeEnum::Parallel,
            1,
            new \DateTimeImmutable(),
            [[
                'batchIndex' => 0,
                'childRunId' => $child,
                'artifactId' => 'agent_cleanup_child',
                'agentName' => 'scout',
                'task' => 'safe task',
                'launchModel' => 'test/model',
                'launchReasoning' => 'off',
            ]],
        );
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $operations = $container->get(DoctrineExecutionOperationStore::class);
        $paths = $container->get(ToolBatchRunStoragePathsInterface::class);

        $parentRequest = new ExecuteLlmStep($parent, 1, 'parent', 1, 'parent-key', 'tools');
        $events->appendTransition([], ['run_id' => $parent, 'predecessor_seq' => 0, 'effects' => [$parentRequest]]);
        $parentPending = $events->verifiedPendingTransition($parent);
        $this->assertNotNull($parentPending);
        $parentAuth = $operations->arm($parentRequest, $parentPending);
        $events->finalizeVerifiedTransition($parent, $parentPending->identity);
        $parentDelivery = $operations->requestReference($parentRequest, $parentAuth);
        $parentClaim = $operations->claim($parentDelivery, $parentAuth);
        $this->assertIsString($parentClaim);
        $parentResult = $operations->saveResult($parentRequest, $parentAuth, $parentClaim, new LlmStepResult($parent, 1, 'parent', 1, 'parent-key'));
        $parentDisposition = new ExecutionResultDispositionDTO($parentResult, 'Consumed');
        $events->appendTransition([], ['run_id' => $parent, 'predecessor_seq' => 0, 'execution_disposition' => $parentDisposition]);
        $parentVerified = $events->verifiedPendingTransition($parent);
        $this->assertNotNull($parentVerified);
        $operations->applyDisposition($parentDisposition, $parentVerified);
        $parentDirectory = \dirname($paths->resolveToolBatchesDirectory($parent)).'/execution-operations/'.$parentResult->effectId;

        $childRequest = new ExecuteLlmStep($child, 1, 'child', 1, 'child-key', 'tools');
        $events->appendTransition([], ['run_id' => $child, 'predecessor_seq' => 0, 'effects' => [$childRequest]]);
        $childPending = $events->verifiedPendingTransition($child);
        $this->assertNotNull($childPending);
        $childAuth = $operations->arm($childRequest, $childPending);
        $events->finalizeVerifiedTransition($child, $childPending->identity);
        $childDelivery = $operations->requestReference($childRequest, $childAuth);
        $childClaim = $operations->claim($childDelivery, $childAuth);
        $this->assertIsString($childClaim);
        $childResult = $operations->saveResult($childRequest, $childAuth, $childClaim, new LlmStepResult($child, 1, 'child', 1, 'child-key'));
        $childDisposition = new ExecutionResultDispositionDTO($childResult, 'Consumed');
        $events->appendTransition([], ['run_id' => $child, 'predecessor_seq' => 0, 'execution_disposition' => $childDisposition]);
        $childVerified = $events->verifiedPendingTransition($child);
        $this->assertNotNull($childVerified);
        $operations->applyDisposition($childDisposition, $childVerified);
        $events->finalizeVerifiedTransition($child, $childVerified->identity);
        $childDirectory = \dirname($paths->resolveToolBatchesDirectory($child)).'/execution-operations/'.$childResult->effectId;
        $this->assertDirectoryExists($parentDirectory);
        $this->assertDirectoryExists($childDirectory);

        $cursor = $operations->reclaimDisposedPayloads($parent, '');
        $this->assertNotSame('', $cursor);
        $this->assertDirectoryExists($parentDirectory, 'Unfinished parent disposal must retain its bodies.');
        $this->assertDirectoryDoesNotExist($childDirectory, 'Finalized child cleanup must continue past parent degradation.');
        $this->assertSame('', $operations->reclaimDisposedPayloads($parent, $cursor));
    }

    public function testOversizedRequestIsRejectedBeforeCanonicalAppendAndAuthorization(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('oversized request admission');
        $active = $container->get(ActiveRunContextInterface::class);
        $active->createNew($run);
        $previous = RunState::queued($run)->with(['status' => RunStatus::Running, 'turnNo' => 1, 'activeStepId' => 'tools']);
        $active->replaceCurrent($previous);
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $operations = $container->get(DoctrineExecutionOperationStore::class);
        $oversized = new ExecuteLlmStep(
            $run,
            1,
            'tools',
            1,
            'oversized-key',
            'tools',
            [new \Ineersa\AgentCore\Domain\Message\AgentMessage(
                role: 'user',
                content: [['type' => 'text', 'text' => str_repeat('x', 17 * 1024 * 1024)]],
            )],
        );
        $event = new RunEvent($run, 1, 0, 'tool_execution_start', ['step_id' => 'tools']);
        $store = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $store->expects($this->once())->method('assertTransitionReady')->with($run);
        $store->expects($this->never())->method('appendTransition');
        $bus = new TestMessageBus();
        $commit = new RunCommit(
            $active,
            $store,
            new StepDispatcher($bus, $bus),
            new TestLogger(),
            new ToolBatchCollector(),
            $container->get(ToolExecutionAuthorizationInterface::class),
            $operations,
            new SourceAcceptance(new InMemoryCommandStore()),
        );
        try {
            $commit->commit($previous, $previous, [$event], [$oversized], dispatchAfterTurnHooks: false);
            $this->fail('Oversized gated requests must refuse before append.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('16 MiB', $exception->getMessage());
        }
        $this->assertNull($events->verifiedPendingTransition($run));
        $this->assertFalse($container->get(\Doctrine\DBAL\Connection::class)->fetchOne(
            'SELECT 1 FROM execution_operation WHERE run_id = ? LIMIT 1',
            [$run],
        ));
        $this->assertSame([], $bus->messages);
        $active->release($run);
    }
}
