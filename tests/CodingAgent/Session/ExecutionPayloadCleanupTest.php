<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\ExecutionResultDispositionDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
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
        $subscriber = new ExecutionPendingDeliverySubscriber($operations, $events, $command, $execution, $run, new TestLogger());
        $subscriber->onStarted(new WorkerStartedEvent(new Worker(['run_control' => new InMemoryTransport()], new TestMessageBus())));
        $this->assertCount(1, $execution->messages);
    }

    public function testMixedToolBatchKeepsBodiesUntilFinalizedThenReclaimsDisposedMembers(): void
    {
        $container = self::getContainer();
        $run = $container->get(HatfieldSessionStore::class)->createSession('mixed tool payload cleanup');
        $gate = $container->get(\Ineersa\AgentCore\Application\Handler\ToolExecutionAuthorization::class);
        $store = $container->get(ToolBatchStoreInterface::class);
        $first = new ExecuteToolCall($run, 1, 'tools', 1, 'first-key', 'call-1', 'read', ['path' => 'a'], 0);
        $second = new ExecuteToolCall($run, 1, 'tools', 1, 'second-key', 'call-2', 'read', ['path' => 'b'], 1);
        (new \Ineersa\AgentCore\Application\Handler\ToolBatchCollector(store: $store))->registerExpectedBatch($run, 1, 'tools', [$first, $second]);
        $gate->arm($first);
        $gate->arm($second);
        $claim = $gate->claim($first);
        $this->assertIsString($claim);
        $result = \Ineersa\AgentCore\Application\Handler\ToolCallResultFactory::fromExecuteToolCallAndToolResult($first, new ToolResult('read-1', 'read', [['type' => 'text', 'text' => 'done']]));
        $gate->saveResult($first, $claim, $result);
        $descriptor = $gate->prepareDisposition($result, 'Consumed');
        $this->assertNotNull($descriptor);
        $events = $container->get(PreparedTransitionEventStoreInterface::class);
        $events->appendTransition([], ['run_id' => $run, 'predecessor_seq' => 0, 'result_disposition' => $descriptor]);
        $verified = $events->verifiedPendingTransition($run);
        $this->assertNotNull($verified);
        $gate->applyDisposition($descriptor, $verified);
        $events->finalizeVerifiedTransition($run, $verified->identity);
        $before = $store->load($run, 1, 'tools');
        $this->assertNotNull($before);
        $this->assertArrayHasKey(array_key_first($before->executionResults), $before->executionResults);
        $this->assertNotSame('', $gate->reclaimDisposedPayloads($run, ''));
        $still = $store->load($run, 1, 'tools');
        $this->assertNotNull($still);
        $this->assertNotEmpty($still->executionResults, 'Unfinalized batches retain disposed member bodies for the canonical commit.');
        $store->mutate($run, 1, 'tools', static function ($batch) {
            $batch->finalized = true;

            return new \Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation(null, $batch);
        });
        $this->assertNotSame('', $gate->reclaimDisposedPayloads($run, ''));
        $after = $store->load($run, 1, 'tools');
        $this->assertNotNull($after);
        $this->assertSame([], $after->executionResults);
        $this->assertSame('Consumed', array_values($after->executionAuthorizations)[0]['state']);
        $this->assertSame('Armed', array_values($after->executionAuthorizations)[1]['state']);
        $this->assertTrue($gate->isDisposed($result));
        $this->assertNull($gate->claim($first));
        $this->assertIsString($gate->claim($second));
    }

    public function testArmedWorkIsRemovedWithVerifiedFinalizationAndInterruptedCleanupRetainsBodies(): void
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('armed-work-cleanup');
        try {
            $path = $dir.'/events.jsonl';
            file_put_contents($path, "{\"seq\":1}\n");
            $journal = new JsonlAppendJournal();
            $request = new ExecuteLlmStep('run-armed', 1, 'step', 1, 'armed-key', 'tools');
            $journal->append($path, ["{\"seq\":2}\n"], ['run_id' => 'run-armed', 'predecessor_seq' => 1, 'effects' => [$request]]);
            $verified = $journal->verifiedPending($path);
            $this->assertNotNull($verified);
            $armed = $path.'.armed-work';
            $this->assertDirectoryExists($armed);
            $before = glob($armed.'/*.json');
            $this->assertIsArray($before);
            $this->assertCount(1, $before);
            $journal->finalizeVerified($path, $verified->identity);
            $this->assertDirectoryDoesNotExist($armed);
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }

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
            $container->get(ToolBatchStoreInterface::class),
        );
        try {
            $broken->reclaimDisposedPayloads($run, '');
            $this->fail('Cleanup failure must surface instead of fabricating completion.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cleanup failed', $exception->getMessage());
        }
        $this->assertDirectoryExists($directory);
        $this->assertFileExists($directory.'/request');
        $this->assertSame($reference->effectId, $operations->reclaimDisposedPayloads($run, ''));
        $this->assertDirectoryDoesNotExist($directory);
    }
}
