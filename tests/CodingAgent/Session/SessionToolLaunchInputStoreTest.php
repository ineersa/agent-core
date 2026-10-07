<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Domain\Tool\ToolLaunchContextDTO;
use Ineersa\AgentCore\Domain\Tool\ToolLaunchInputReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\Serializer as MessengerSerializer;
use Symfony\Component\Serializer\SerializerInterface;

final class SessionToolLaunchInputStoreTest extends IsolatedKernelTestCase
{
    public function testLargeHistoryDoesNotGrowTransportOrBatchAndSiblingMutationDoesNotReadInput(): void
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('input-size');
        $store = self::getContainer()->get(ToolLaunchInputStoreInterface::class);
        $serializer = new MessengerSerializer(self::getContainer()->get(SerializerInterface::class), 'json');
        $batchStore = self::getContainer()->get(ToolBatchStoreInterface::class);
        $paths = self::getContainer()->get(ToolBatchRunStoragePathsInterface::class);
        $sizes = [];
        foreach ([1, 600] as $count) {
            $callId = 'fork-'.$count;
            $messages = (static function () use ($count): \Generator {
                for ($i = 0; $i < $count; ++$i) {
                    yield new AgentMessage('user', [['type' => 'text', 'text' => 'PRIVATE_LAUNCH_BODY'.str_repeat('x', 2048)]]);
                }
            })();
            $reference = $store->publish('fork', $runId, 1, 'step-'.$count, $callId, 'model', '', $messages);
            $call = $this->call($reference);
            $body = $serializer->encode(new Envelope($call))['body'];
            $this->assertStringNotContainsString('PRIVATE_LAUNCH_BODY', $body);
            $readCall = new ExecuteToolCall($runId, 1, 'step-'.$count, 1, 'read-key', 'read', 'read', ['path' => 'x'], 1);
            $batch = new ToolBatchStateDTO(expectedOrder: [$callId => 0, 'read' => 1], calls: [$callId => $call, 'read' => $readCall], pendingQueue: [$callId], inFlight: [], results: [], finalized: false, maxParallelism: 2);
            $batchStore->save($runId, 1, 'step-'.$count, $batch);
            $batchFiles = glob($paths->resolveToolBatchesDirectory($runId).'/*.json');
            $batchPath = end($batchFiles);
            // Locate the exact envelope rather than relying on filename order.
            foreach ($batchFiles as $candidate) {
                if (str_contains(file_get_contents($candidate), $callId)) {
                    $batchPath = $candidate;
                    break;
                }
            }
            $batchBody = file_get_contents($batchPath);
            $this->assertStringNotContainsString('PRIVATE_LAUNCH_BODY', $batchBody);
            $sizes[] = [\strlen($body), \strlen($batchBody), $reference->bytes];
            $payloadPath = $this->payloadPath($runId, $callId);
            $original = hash_file('sha256', $payloadPath);
            $readResult = \Ineersa\AgentCore\Tests\Support\Builder\ToolCallResultBuilder::success($runId)->withTurnNo(1)->withStepId('step-'.$count)->withToolCallId('read')->withOrderIndex(1)->build();
            $batchStore->mutate($runId, 1, 'step-'.$count, static function ($current) use ($readResult): ToolBatchStoreMutation {
                $current->results['read'] = $readResult;

                return new ToolBatchStoreMutation(null, $current);
            });
            $this->assertSame($original, hash_file('sha256', $payloadPath));
            // If mutate tried to resolve launch input, this corruption would fail.
            file_put_contents($payloadPath, 'unreadable-as-input');
            $batchStore->mutate($runId, 1, 'step-'.$count, static fn ($current) => new ToolBatchStoreMutation(null, $current));
            $this->assertSame('unreadable-as-input', file_get_contents($payloadPath));
            $this->assertNotSame($original, hash_file('sha256', $payloadPath));
            $this->assertNotNull($batchStore->load($runId, 1, 'step-'.$count));
        }
        $this->assertLessThan(32, abs($sizes[1][0] - $sizes[0][0]));
        $this->assertLessThan(32, abs($sizes[1][1] - $sizes[0][1]));
        $this->assertGreaterThan(1000000, $sizes[1][2]);
        $this->assertLessThan(2000, $sizes[1][0]);
        $this->assertLessThan(4000, $sizes[1][1]);
        fwrite(\STDERR, json_encode(['launch_input_sizes' => $sizes], \JSON_THROW_ON_ERROR)."\n");
    }

    public function testRichInputReachesWorkerThroughConfiguredReferenceAndSurvivesApprovalCopy(): void
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('rich-input');
        $store = self::getContainer()->get(ToolLaunchInputStoreInterface::class);
        $messages = [
            new AgentMessage('assistant', [
                ['type' => 'thinking', 'thinking' => 'reasoning', 'signature' => 'signature-value'],
                ['type' => 'encrypted_thinking', 'data' => 'encrypted-value'],
                ['type' => 'attachment', 'reference' => 'private-attachment-ref'],
            ], new \DateTimeImmutable('2026-10-02T10:00:00+00:00'), name: 'assistant-name', details: ['custom' => 42], metadata: ['tool_calls' => [['id' => 'read-1', 'name' => 'read', 'arguments' => ['path' => 'x']]], 'provider' => 'custom-provider']),
            new AgentMessage('tool', [['type' => 'text', 'text' => 'read-result']], toolCallId: 'read-1', toolName: 'read', details: ['exit_code' => 0], metadata: ['nondefault' => true]),
        ];
        $reference = $store->publish('fork', $runId, 1, 'step', 'fork-call', 'model', 'AGENTS BODY', $messages);
        $serializer = new MessengerSerializer(self::getContainer()->get(SerializerInterface::class), 'json');
        $call = $serializer->decode($serializer->encode(new Envelope($this->call($reference))))->getMessage();
        $call = $call->withHumanInputAnswer(new \Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO('question', ['approved' => true], [], []));
        $this->assertSame($reference->sha256, $call->launchContext->sha256);
        $executor = $this->createMock(ToolExecutorInterface::class);
        $executor->expects($this->once())->method('execute')->willReturnCallback(function ($toolCall) use ($messages): ToolResult {
            $input = $toolCall->context['launch_context'];
            $this->assertInstanceOf(ToolLaunchContextDTO::class, $input);
            $this->assertEquals($messages, $input->forkMessages);
            $this->assertSame('AGENTS BODY', $input->agentsContext);
            $this->assertSame('signature-value', $input->forkMessages[0]->content[0]['signature']);
            $this->assertSame('encrypted-value', $input->forkMessages[0]->content[1]['data']);
            $this->assertSame('private-attachment-ref', $input->forkMessages[0]->content[2]['reference']);
            $this->assertSame('read-1', $input->forkMessages[1]->toolCallId);

            return new ToolResult('fork-call', 'fork', [['type' => 'text', 'text' => 'launch failed']], isError: true);
        });
        $bus = new TestMessageBus();
        $worker = new ExecuteToolCallWorker($executor, $this->createStub(DeferredToolCompletionRepositoryInterface::class), new ToolExecutionResultStore(), $this->createStub(RunOperationalStatusReaderInterface::class), launchInputStore: $store);
        $worker($call);
        $this->assertFileExists($this->payloadPath($runId, 'fork-call'));
    }

    public function testImmutableRetryAndIdempotentDeletion(): void
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('immutable-input');
        $store = self::getContainer()->get(ToolLaunchInputStoreInterface::class);
        $reference = $store->publish('fork', $runId, 1, 'step', 'call', 'model', '', [new AgentMessage('user', [['type' => 'text', 'text' => 'original']])]);
        $retry = $store->publish('fork', $runId, 1, 'step', 'call', 'model', '', [new AgentMessage('user', [['type' => 'text', 'text' => 'original']])]);
        $this->assertEquals($reference, $retry);
        try {
            $store->publish('fork', $runId, 2, 'other-step', 'call', 'model', '', []);
            $this->fail('Conflicting retry must not overwrite input.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Conflicting immutable tool launch input.', $exception->getMessage());
        }
        $this->assertSame('original', $store->read($reference)->forkMessages[0]->content[0]['text']);
        $store->delete($runId, 'call');
        $store->delete($runId, 'call');
        $this->assertFileDoesNotExist($this->payloadPath($runId, 'call'));
        $this->expectException(\RuntimeException::class);
        $store->read($reference);
    }

    public function testForgedIdentityAndCorruptionFailClosed(): void
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('input-tamper');
        $store = self::getContainer()->get(ToolLaunchInputStoreInterface::class);
        $reference = $store->publish('fork', $runId, 1, 'step', 'call', 'model', '', []);
        $forged = new ToolLaunchInputReferenceDTO('fork', $runId, 2, 'step', 'call', 'model', $reference->sha256, $reference->bytes);
        try {
            $store->read($forged);
            $this->fail('Forged producing identity must fail.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Tool launch input ownership mismatch.', $exception->getMessage());
        }
        file_put_contents($this->payloadPath($runId, 'call'), 'corrupt');
        $this->expectException(\RuntimeException::class);
        $store->read($reference);
    }

    public function testPublicationHasBoundedWorkingMemoryAndDoesNotRetainOwnerMessages(): void
    {
        $directory = \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::createProjectTempDir('launch-input-memory');
        try {
            $process = new \Symfony\Component\Process\Process([\PHP_BINARY, '-d', 'memory_limit=128M', __DIR__.'/Fixtures/tool-launch-input-memory.php', $directory]);
            $process->setTimeout(8);
            $process->mustRun();
            $metrics = json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
            fwrite(\STDERR, json_encode(['launch_input_memory' => $metrics], \JSON_THROW_ON_ERROR)."\n");
            $this->assertGreaterThan(32000000, $metrics['input_bytes']);
            $this->assertLessThan(8 * 1024 * 1024, $metrics['peak_bytes'] - $metrics['owner_allocated_bytes']);
            $this->assertTrue($metrics['source_released']);
        } finally {
            \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testReferencePathTraversalIsRejected(): void
    {
        $store = self::getContainer()->get(ToolLaunchInputStoreInterface::class);
        $this->expectException(\InvalidArgumentException::class);
        $store->read(new ToolLaunchInputReferenceDTO('fork', '../other-session', 1, 'step', 'call', 'model', str_repeat('a', 64), 1));
    }

    public function testChildInputUsesArtifactRuntimeDirectoryNotChildTopLevelSession(): void
    {
        $hatfield = self::getContainer()->get(HatfieldSessionStore::class);
        $parent = $hatfield->createSession('child-input-location');
        $child = 'launch-input-child';
        $artifact = 'agent_launch_input_child';
        $lifecycle = self::getContainer()->get(\Ineersa\CodingAgent\Agent\Execution\ChildRun\Lifecycle\ChildRunArtifactLifecycleService::class);
        $lifecycle->ensureReservedPending(new \Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunIdentityDTO($parent, $child, $artifact, 'worker', 'task', \Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum::Subagent));
        $store = self::getContainer()->get(ToolLaunchInputStoreInterface::class);
        $reference = $store->publish('subagent', $child, 1, 'step', 'call', 'model', 'agents-text', []);
        $resolver = self::getContainer()->get(\Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver::class);
        $this->assertFileExists($resolver->resolveArtifactDir($parent, $artifact).'/runtime/tool-launch-inputs/'.hash('sha256', 'call').'.jsonl');
        $this->assertDirectoryDoesNotExist($hatfield->resolveSessionsBasePath().'/'.$child);
        $this->assertSame('agents-text', $store->read($reference)->agentsContext);
        $this->assertSame([], $store->read($reference)->forkMessages);
    }

    public function testWrongEnvelopeIdentityFailsBeforeInputReadOrExternalWork(): void
    {
        $executor = $this->createMock(ToolExecutorInterface::class);
        $executor->expects($this->never())->method('execute');
        $inputStore = $this->createMock(ToolLaunchInputStoreInterface::class);
        $inputStore->expects($this->never())->method('read');
        $reference = new ToolLaunchInputReferenceDTO('fork', 'owner', 2, 'step', 'call', 'model', str_repeat('a', 64), 1);
        $call = new ExecuteToolCall('owner', 1, 'step', 1, 'key', 'call', 'fork', [], 0, parentModel: 'model', launchContext: $reference);
        $bus = new TestMessageBus();
        $worker = new ExecuteToolCallWorker($executor, $this->createStub(DeferredToolCompletionRepositoryInterface::class), new ToolExecutionResultStore(), $this->createStub(RunOperationalStatusReaderInterface::class), launchInputStore: $inputStore);
        $worker($call);
        $this->assertOwnerVisibleInputFailure($bus, 'Tool launch input reference does not match execution envelope.');
    }

    public function testMissingInputFailsBeforeExternalWork(): void
    {
        $executor = $this->createMock(ToolExecutorInterface::class);
        $executor->expects($this->never())->method('execute');
        $inputStore = self::getContainer()->get(ToolLaunchInputStoreInterface::class);
        $reference = new ToolLaunchInputReferenceDTO('fork', 'missing-owner', 1, 'step', 'call', 'model', str_repeat('a', 64), 1);
        $bus = new TestMessageBus();
        $worker = new ExecuteToolCallWorker($executor, $this->createStub(DeferredToolCompletionRepositoryInterface::class), new ToolExecutionResultStore(), $this->createStub(RunOperationalStatusReaderInterface::class), launchInputStore: $inputStore);
        $worker($this->call($reference));
        $this->assertOwnerVisibleInputFailure($bus, 'Missing or corrupt tool launch input.');
    }

    public function testCorruptInputProducesErrorResultWithoutExternalExecution(): void
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('corrupt-input-disposition');
        $inputStore = self::getContainer()->get(ToolLaunchInputStoreInterface::class);
        $reference = $inputStore->publish('fork', $runId, 1, 'step', 'call', 'model', '', []);
        file_put_contents($this->payloadPath($runId, 'call'), 'corrupt');
        $executor = $this->createMock(ToolExecutorInterface::class);
        $executor->expects($this->never())->method('execute');
        $bus = new TestMessageBus();
        $worker = new ExecuteToolCallWorker($executor, $this->createStub(DeferredToolCompletionRepositoryInterface::class), new ToolExecutionResultStore(), $this->createStub(RunOperationalStatusReaderInterface::class), launchInputStore: $inputStore);
        $worker($this->call($reference));
        $this->assertOwnerVisibleInputFailure($bus, 'Missing or corrupt tool launch input.');
    }

    public function testUnreadableInputProducesErrorResultWithoutExternalExecution(): void
    {
        $inputStore = $this->createMock(ToolLaunchInputStoreInterface::class);
        $inputStore->expects($this->once())->method('read')->willThrowException(new \RuntimeException('Cannot open tool launch input.'));
        $executor = $this->createMock(ToolExecutorInterface::class);
        $executor->expects($this->never())->method('execute');
        $reference = new ToolLaunchInputReferenceDTO('fork', 'owner', 1, 'step', 'call', 'model', str_repeat('a', 64), 1);
        $bus = new TestMessageBus();
        $worker = new ExecuteToolCallWorker($executor, $this->createStub(DeferredToolCompletionRepositoryInterface::class), new ToolExecutionResultStore(), $this->createStub(RunOperationalStatusReaderInterface::class), launchInputStore: $inputStore);
        $worker($this->call($reference));
        $this->assertOwnerVisibleInputFailure($bus, 'Cannot open tool launch input.');
    }

    public function testFreshWorkerReusesCommittedSynchronousForkFailureWhileSiblingRemainsPending(): void
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession('mixed-fork-failure');
        $inputStore = self::getContainer()->get(ToolLaunchInputStoreInterface::class);
        $batchStore = self::getContainer()->get(ToolBatchStoreInterface::class);
        $reference = $inputStore->publish('fork', $runId, 1, 'step', 'fork', 'model', '', [new AgentMessage('user', [['type' => 'text', 'text' => 'input']])]);
        $fork = new ExecuteToolCall($runId, 1, 'step', 1, 'fork-request', 'fork', 'fork', ['task' => 'work'], 0, mode: 'parallel', maxParallelism: 2, parentModel: 'model', launchContext: $reference);
        $sibling = new ExecuteToolCall($runId, 1, 'step', 1, 'sibling-request', 'sibling', 'read', ['path' => 'x'], 1, mode: 'parallel', maxParallelism: 2);
        $ownerCollector = new \Ineersa\AgentCore\Application\Handler\ToolBatchCollector(store: $batchStore);
        $ownerCollector->registerExpectedBatch($runId, 1, 'step', [$fork, $sibling]);
        $executor = $this->createMock(ToolExecutorInterface::class);
        $executor->expects($this->once())->method('execute')->willThrowException(new \Ineersa\AgentCore\Contract\Tool\ToolCallException('Original fork preparation failure.', retryable: false, hint: 'original hint'));
        $bus = new TestMessageBus();
        $deferred = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $worker = new ExecuteToolCallWorker($executor, $deferred, new ToolExecutionResultStore(), $this->createStub(RunOperationalStatusReaderInterface::class), launchInputStore: $inputStore);
        $worker($fork);
        $this->assertCount(1, $bus->messages);
        $originalFailure = $bus->messages[0];
        $this->assertTrue($originalFailure->isError);
        $this->assertSame('Original fork preparation failure.', $originalFailure->error['message']);
        $state = \Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder::running($runId)->withTurnNo(1)->withActiveStepId('step')->withLastSeq(0)->withPendingToolCalls(['fork' => false, 'sibling' => false])->build()->with(['model' => 'model']);
        $handler = new \Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler(
            toolBatchCollector: $ownerCollector,
            eventFactory: new \Ineersa\AgentCore\Domain\Event\EventFactory(),
            toolCallExtractor: new \Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor(),
            messageNormalizer: new \Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer(),
            serializer: self::getContainer()->get(SerializerInterface::class),
        );
        $transition = $handler->handle($originalFailure, $state);
        $this->assertCount(1, $transition->events);
        $this->assertSame('tool_execution_end', $transition->events[0]->type);
        $active = new \Ineersa\AgentCore\Tests\Support\TestActiveRunContext();
        $active->loadRecovered($state);
        $eventStore = self::getContainer()->get(\Ineersa\AgentCore\Contract\EventStoreInterface::class);
        $commit = new \Ineersa\AgentCore\Application\Pipeline\RunCommit(
            activeRunContext: $active,
            eventStore: $eventStore,
            logger: new \Ineersa\AgentCore\Tests\Support\TestLogger(),
            toolBatchCollector: $ownerCollector,
            executionOperations: new \Ineersa\AgentCore\Tests\Support\TestExecutionOperationStore(),
            sourceAcceptance: new \Ineersa\AgentCore\Application\Pipeline\SourceAcceptance(new \Ineersa\AgentCore\Tests\Support\InMemoryCommandStore()),
            hookDispatcher: new \Ineersa\AgentCore\Application\Handler\HookDispatcher([self::getContainer()->get(\Ineersa\CodingAgent\Session\ToolBatchSnapshotCleanupHookSubscriber::class)]),
            finalizer: TestTransitionFinalizerFactory::create($eventStore, new \Ineersa\AgentCore\Application\Handler\StepDispatcher(self::getContainer()->get('agent.command.bus'), new TestMessageBus())),
        );
        $commit->commit($state, $transition->nextState, $transition->events, $transition->effects, postCommitEffects: $transition->postCommitEffects, postCommitActions: $transition->postCommitActions);
        $this->assertFileDoesNotExist($this->payloadPath($runId, 'fork'));
        $this->assertNull($deferred->findByRunAndToolCall($runId, 'fork'));
        $this->assertEquals($originalFailure, $batchStore->load($runId, 1, 'step')->results['fork']);
        $this->assertCount(1, iterator_to_array($eventStore->rangeFor($runId, 1, 1)));

        $freshExecutor = $this->createMock(ToolExecutorInterface::class);
        $freshExecutor->expects($this->never())->method('execute');
        $unreadInput = $this->createMock(ToolLaunchInputStoreInterface::class);
        $unreadInput->expects($this->never())->method('read');
        $freshBus = new TestMessageBus();
        $freshWorker = new ExecuteToolCallWorker($freshExecutor, $this->createStub(DeferredToolCompletionRepositoryInterface::class), new ToolExecutionResultStore(), $this->createStub(RunOperationalStatusReaderInterface::class), launchInputStore: $unreadInput);
        $freshWorker($fork);
        $this->assertCount(1, $freshBus->messages);
        $this->assertEquals($originalFailure, $freshBus->messages[0]);
        $this->assertSame('original hint', $freshBus->messages[0]->error['hint']);
        $duplicate = \Ineersa\AgentCore\Tests\Support\TestToolBatchCoordination::collect($ownerCollector, $freshBus->messages[0]);
        $this->assertTrue($duplicate->duplicate);
        $this->assertFalse($duplicate->complete);
        $unchanged = $handler->handle($freshBus->messages[0], $active->requireLoaded($runId));
        $this->assertSame([], $unchanged->events);
        $batch = $batchStore->load($runId, 1, 'step');
        $this->assertFalse($batch->finalized);
        $this->assertArrayHasKey('sibling', $batch->inFlight);
        $this->assertArrayNotHasKey('sibling', $batch->results);
        $this->assertFalse($active->requireLoaded($runId)->pendingToolCalls['sibling']);
    }

    private function assertOwnerVisibleInputFailure(TestMessageBus $bus, string $message): void
    {
        $this->assertCount(1, $bus->messages);
        $result = $bus->messages[0];
        $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ToolCallResult::class, $result);
        $this->assertTrue($result->isError);
        $this->assertSame('call', $result->toolCallId);
        $this->assertSame($message, $result->error['message']);
    }

    private function call(ToolLaunchInputReferenceDTO $reference): ExecuteToolCall
    {
        return new ExecuteToolCall($reference->producingRunId, $reference->producingTurnNo, $reference->producingStepId, 1, 'key', $reference->toolCallId, $reference->kind, [], 0, parentModel: $reference->producingModel, launchContext: $reference);
    }

    private function payloadPath(string $runId, string $callId): string
    {
        $paths = self::getContainer()->get(ToolBatchRunStoragePathsInterface::class);

        return \dirname($paths->resolveToolBatchesDirectory($runId)).'/tool-launch-inputs/'.hash('sha256', $callId).'.jsonl';
    }
}
