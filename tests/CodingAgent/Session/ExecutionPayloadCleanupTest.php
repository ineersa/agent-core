<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Runtime\Messenger\ExecutionPendingDeliverySubscriber;
use Ineersa\CodingAgent\Session\DoctrineExecutionOperationStore;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\JsonlAppendJournal;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Messenger\Event\WorkerStartedEvent;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Messenger\Worker;

final class ExecutionPayloadCleanupTest extends IsolatedKernelTestCase
{
    public function testDisposedExecutionPayloadsReclaimAfterFinalizationAndRejectLateBodies(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('execution payload cleanup');
        $request = new ExecuteLlmStep($run, 1, 'execution', 1, 'cleanup-request', 'tools');
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $operations = $container->get(DoctrineExecutionOperationStore::class);
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $pending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $authorization = $operations->arm($request, $pending);
        $events->finalizeVerifiedTransition($run, $pending->identity);
        $delivery = $operations->requestReference($request, $authorization);
        $claim = $operations->claim($delivery, $authorization);
        $this->assertIsString($claim);
        $result = new LlmStepResult($run, 1, 'execution', 1, 'cleanup-request');
        $reference = $operations->saveResult($request, $authorization, $claim, $result);
        $paths = $container->get(ToolBatchRunStoragePathsInterface::class);
        $directory = \dirname($paths->resolveToolBatchesDirectory($run)).'/execution-operations/'.$reference->effectId;
        $this->assertDirectoryExists($directory);
        $descriptor = new ExecutionResultDispositionDTO($reference, 'Consumed');
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'execution_disposition' => $descriptor]);
        $verified = $events->verifiedPendingTransition($run);
        $this->assertNotNull($verified);
        $operations->applyDisposition($descriptor, $verified);
        $this->assertDirectoryExists($directory, 'Disposition alone must not delete request/result bodies.');
        $events->finalizeVerifiedTransition($run, $verified->identity);
        $this->assertSame($reference->effectId, $operations->reclaimDisposedPayloads($run, ''));
        $this->assertDirectoryDoesNotExist($directory);
        $this->assertTrue($operations->isDisposed($reference));
        $this->assertEquals($reference, $operations->resultForClaim($delivery, $authorization, $claim));
        $this->assertNull($operations->claim($delivery, $authorization));
        try {
            $operations->resolveResult($reference);
            $this->fail('Reclaimed bodies must not decode into a new execution result.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('reclaimed', $exception->getMessage());
        }
        $this->assertSame('', $operations->reclaimDisposedPayloads($run, $reference->effectId));
    }

    public function testIdleSweepPagesDisposedPayloadCleanupAndLeavesLiveWork(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('paged payload cleanup');
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $operations = $container->get(DoctrineExecutionOperationStore::class);
        $disposed = [];
        for ($i = 0; $i < 33; ++$i) {
            $request = new ExecuteLlmStep($run, 1, 'page-'.$i, 1, 'cleanup-page-'.$i, 'tools');
            $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
            $pending = $events->verifiedPendingTransition($run);
            $this->assertNotNull($pending);
            $authorization = $operations->arm($request, $pending);
            $events->finalizeVerifiedTransition($run, $pending->identity);
            $delivery = $operations->requestReference($request, $authorization);
            $claim = $operations->claim($delivery, $authorization);
            $this->assertIsString($claim);
            $reference = $operations->saveResult($request, $authorization, $claim, new LlmStepResult($run, 1, 'page-'.$i, 1, 'cleanup-page-'.$i));
            $descriptor = new ExecutionResultDispositionDTO($reference, 'Consumed');
            $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'execution_disposition' => $descriptor]);
            $verified = $events->verifiedPendingTransition($run);
            $this->assertNotNull($verified);
            $operations->applyDisposition($descriptor, $verified);
            $events->finalizeVerifiedTransition($run, $verified->identity);
            $disposed[] = $reference->effectId;
        }
        $live = new ExecuteLlmStep($run, 1, 'live', 1, 'cleanup-live', 'tools');
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$live]]);
        $pending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $liveAuthorization = $operations->arm($live, $pending);
        $events->finalizeVerifiedTransition($run, $pending->identity);
        $paths = $container->get(ToolBatchRunStoragePathsInterface::class);
        $first = $operations->reclaimDisposedPayloads($run, '');
        $this->assertNotSame('', $first);
        $second = $operations->reclaimDisposedPayloads($run, $first);
        $this->assertNotSame('', $second);
        $this->assertSame('', $operations->reclaimDisposedPayloads($run, $second));
        foreach ($disposed as $effectId) {
            $this->assertDirectoryDoesNotExist(\dirname($paths->resolveToolBatchesDirectory($run)).'/execution-operations/'.$effectId);
        }
        $livePath = \dirname($paths->resolveToolBatchesDirectory($run)).'/execution-operations/'.$liveAuthorization->effectId;
        $this->assertFileExists($livePath.'/request');
        $command = new TestMessageBus();
        $execution = new TestMessageBus();
        $subscriber = new ExecutionPendingDeliverySubscriber($operations, $events, self::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class), self::getContainer()->get(\Ineersa\AgentCore\Application\Pipeline\DurablePendingPublication::class), self::getContainer()->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class), self::getContainer()->get(\Doctrine\DBAL\Connection::class), $command, $execution, $run, new TestLogger());
        $subscriber->onStarted(new WorkerStartedEvent(new Worker(['run_control' => new InMemoryTransport()], new TestMessageBus())));
        $this->assertCount(1, $execution->messages);
    }

    public function testPendingDispositionWithoutFinalizationRetainsPayloadsAndIntent(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('pending disposition cleanup');
        $request = new ExecuteLlmStep($run, 1, 'pending', 1, 'pending-key', 'tools');
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $operations = $container->get(DoctrineExecutionOperationStore::class);
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $pending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $authorization = $operations->arm($request, $pending);
        $events->finalizeVerifiedTransition($run, $pending->identity);
        $delivery = $operations->requestReference($request, $authorization);
        $claim = $operations->claim($delivery, $authorization);
        $this->assertIsString($claim);
        $reference = $operations->saveResult($request, $authorization, $claim, new LlmStepResult($run, 1, 'pending', 1, 'pending-key'));
        $descriptor = new ExecutionResultDispositionDTO($reference, 'Consumed');
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'execution_disposition' => $descriptor]);
        $verified = $events->verifiedPendingTransition($run);
        $this->assertNotNull($verified);
        $operations->applyDisposition($descriptor, $verified);
        $paths = $container->get(ToolBatchRunStoragePathsInterface::class);
        $directory = \dirname($paths->resolveToolBatchesDirectory($run)).'/execution-operations/'.$reference->effectId;
        $eventsPath = $paths->resolveToolBatchesDirectory($run);
        $eventsPath = \dirname($eventsPath, 2).'/events.jsonl';
        $this->assertFileExists($eventsPath.'.append.pending.json');
        $this->assertFileExists($eventsPath.'.append.work');
        $this->assertDirectoryExists($directory);
        $this->assertSame($reference->effectId, $operations->reclaimDisposedPayloads($run, ''), 'Unfinished disposal advances without deleting bodies.');
        $this->assertDirectoryExists($directory);
        $this->assertFileExists($directory.'/request');
        $this->assertFileExists($eventsPath.'.append.pending.json');
        $this->assertFileExists($eventsPath.'.append.work');
        $this->assertDirectoryDoesNotExist($eventsPath.'.armed-work');
    }

    public function testJournalDoesNotCopyArmedWorkAndRetainsPendingIntentUntilFinalization(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('pending-intent-retention');
        try {
            $path = $dir.'/events.jsonl';
            file_put_contents($path, "{\"seq\":1}\n");
            $journal = new JsonlAppendJournal();
            $request = new ExecuteLlmStep('run-intent', 1, 'step', 1, 'intent-key', 'tools');
            $journal->append($path, ["{\"seq\":2}\n"], ['run_id' => 'run-intent', 'predecessor_seq' => 1, 'effects' => [$request]]);
            $verified = $journal->verifiedPending($path);
            $this->assertNotNull($verified);
            $this->assertDirectoryDoesNotExist($path.'.armed-work');
            $this->assertFileExists($path.'.append.pending.json');
            $this->assertFileExists($path.'.append.work');
            $journal->finalizeVerified($path, $verified->identity);
            $this->assertFileDoesNotExist($path.'.append.pending.json');
            $this->assertFileDoesNotExist($path.'.append.work');
            $this->assertDirectoryDoesNotExist($path.'.armed-work');
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    public function testPartialDirectoryDeletionRetriesUntilSurvivingPayloadsAreGone(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('partial payload cleanup');
        $request = new ExecuteLlmStep($run, 1, 'partial', 1, 'partial-key', 'tools');
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $operations = $container->get(DoctrineExecutionOperationStore::class);
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $pending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $authorization = $operations->arm($request, $pending);
        $events->finalizeVerifiedTransition($run, $pending->identity);
        $delivery = $operations->requestReference($request, $authorization);
        $claim = $operations->claim($delivery, $authorization);
        $this->assertIsString($claim);
        $reference = $operations->saveResult($request, $authorization, $claim, new LlmStepResult($run, 1, 'partial', 1, 'partial-key'));
        $descriptor = new ExecutionResultDispositionDTO($reference, 'Consumed');
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'execution_disposition' => $descriptor]);
        $verified = $events->verifiedPendingTransition($run);
        $this->assertNotNull($verified);
        $operations->applyDisposition($descriptor, $verified);
        $events->finalizeVerifiedTransition($run, $verified->identity);
        $paths = $container->get(ToolBatchRunStoragePathsInterface::class);
        $directory = \dirname($paths->resolveToolBatchesDirectory($run)).'/execution-operations/'.$reference->effectId;
        $this->assertFileExists($directory.'/request');
        $this->assertFileExists($directory.'/'.hash('sha256', $claim).'.result');
        unlink($directory.'/request');
        $this->assertFileDoesNotExist($directory.'/request');
        $this->assertDirectoryExists($directory);
        $this->assertSame($reference->effectId, $operations->reclaimDisposedPayloads($run, ''));
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function testUnknownRetirementLeavesPayloadsUntilFinalizedCleanupAndDropsRetiredBodies(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('unknown retirement cleanup');
        $request = new ExecuteLlmStep($run, 1, 'unknown', 1, 'unknown-key', 'tools');
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $operations = $container->get(DoctrineExecutionOperationStore::class);
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $pending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $authorization = $operations->arm($request, $pending);
        $events->finalizeVerifiedTransition($run, $pending->identity);
        $delivery = $operations->requestReference($request, $authorization);
        $worker = new DoctrineExecutionOperationStore(
            $container->get(\Doctrine\DBAL\Connection::class),
            $container->get(ToolBatchRunStoragePathsInterface::class),
            new \Symfony\Component\Filesystem\Filesystem(),
            $container->get('hatfield.controller.session_owner.lock_factory'),
            $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class),
            $events,
            $container->get(\Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface::class),
        );
        $claim = $worker->claim($delivery, $authorization);
        $this->assertIsString($claim);
        unset($worker);
        $operations->pendingDeliveries($run, '');
        $notice = $operations->unknownExecutionsForRepair($run)[0];
        $paths = $container->get(ToolBatchRunStoragePathsInterface::class);
        $directory = \dirname($paths->resolveToolBatchesDirectory($run)).'/execution-operations/'.$notice->effectId;
        $this->assertDirectoryExists($directory);
        $this->assertFileExists($directory.'/request');
        $action = new \Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO($notice);
        $events->appendTransition([], [
            'run_id' => $run,
            'predecessor_seq' => 0,
            'source' => ['command_type' => \Ineersa\AgentCore\Domain\Coordination\RetireUnknownExecutionDTO::class, 'command_id' => 'repair-cleanup'],
            'actions' => [$action],
        ]);
        $verified = $events->verifiedPendingTransition($run);
        $this->assertNotNull($verified);
        $operations->retireUnknownExecution($action, $verified);
        $this->assertDirectoryExists($directory, 'Retirement during a pending transition must not delete payloads.');
        $this->assertFileExists($directory.'/request');
        $this->assertSame($notice->effectId, $operations->reclaimDisposedPayloads($run, ''), 'Unfinished disposal advances the page without deleting bodies.');
        $this->assertDirectoryExists($directory);
        $this->assertFileExists($directory.'/request');
        $events->finalizeVerifiedTransition($run, $verified->identity);
        $this->assertSame($notice->effectId, $operations->reclaimDisposedPayloads($run, ''));
        $this->assertDirectoryDoesNotExist($directory);
    }

    public function testInterruptedCleanupRetainsBodiesAndConvergesOnRetry(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('interrupted payload cleanup');
        $request = new ExecuteLlmStep($run, 1, 'interrupt', 1, 'interrupt-key', 'tools');
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $operations = $container->get(DoctrineExecutionOperationStore::class);
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'effects' => [$request]]);
        $pending = $events->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $authorization = $operations->arm($request, $pending);
        $events->finalizeVerifiedTransition($run, $pending->identity);
        $delivery = $operations->requestReference($request, $authorization);
        $claim = $operations->claim($delivery, $authorization);
        $this->assertIsString($claim);
        $reference = $operations->saveResult($request, $authorization, $claim, new LlmStepResult($run, 1, 'interrupt', 1, 'interrupt-key'));
        $descriptor = new ExecutionResultDispositionDTO($reference, 'Consumed');
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'execution_disposition' => $descriptor]);
        $verified = $events->verifiedPendingTransition($run);
        $this->assertNotNull($verified);
        $operations->applyDisposition($descriptor, $verified);
        $events->finalizeVerifiedTransition($run, $verified->identity);
        $paths = $container->get(ToolBatchRunStoragePathsInterface::class);
        $directory = \dirname($paths->resolveToolBatchesDirectory($run)).'/execution-operations/'.$reference->effectId;
        $failing = new class extends \Symfony\Component\Filesystem\Filesystem {
            public function remove(iterable|string $files): void
            {
                throw new \RuntimeException('injected payload cleanup interruption');
            }
        };
        $broken = new DoctrineExecutionOperationStore(
            $container->get(\Doctrine\DBAL\Connection::class),
            $paths,
            $failing,
            $container->get('hatfield.controller.session_owner.lock_factory'),
            $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class),
            $container->get(PreparedTransitionEventStoreInterface::class),
            $container->get(\Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface::class),
        );
        $this->assertSame($reference->effectId, $broken->reclaimDisposedPayloads($run, ''), 'Local cleanup degradation advances the page without inventing success.');
        $this->assertDirectoryExists($directory);
        $this->assertFileExists($directory.'/request');
        $this->assertSame($reference->effectId, $operations->reclaimDisposedPayloads($run, ''));
        $this->assertDirectoryDoesNotExist($directory);
    }
}
