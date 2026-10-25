<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Handler\CompleteDeferredToolCallHandler;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DTO\DeferredToolCompletionMetadataDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Runtime\Messenger\ToolPayloadMiddleware;
use Ineersa\CodingAgent\Session\CanonicalToolInvocationReader;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Session\Contract\RunSequenceAllocatorInterface;
use Ineersa\CodingAgent\Session\EventLogMaxSeqBootstrapReader;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Ineersa\CodingAgent\Session\JsonlRunEventLog;
use Ineersa\CodingAgent\Session\RunHistorySourceDTO;
use Ineersa\CodingAgent\Session\SessionToolPayloadStore;
use Ineersa\CodingAgent\Session\ToolBatchRunStoragePathsInterface;
use Ineersa\CodingAgent\Session\ToolPayloadAdmission;
use Ineersa\CodingAgent\Tests\Support\HistoryEventStoreFactory;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CanonicalToolInvocationReaderTest extends IsolatedKernelTestCase
{
    private JsonlRunEventLog $log;
    private CanonicalToolInvocationReader $reader;
    private TestLogger $logger;
    private string $path;
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $container = self::getContainer();
        $this->logger = new TestLogger();
        $this->log = new JsonlRunEventLog($container->get(EventPayloadNormalizer::class), $container->get(LockFactory::class), $container->get(RunSequenceAllocatorInterface::class), new EventLogMaxSeqBootstrapReader(), $this->logger, presentations: HistoryEventStoreFactory::presentationFactory());
        $this->path = getcwd().'/.hatfield/sessions/indexed/events.jsonl';
        $container->get(Filesystem::class)->mkdir(\dirname($this->path));
        $sources = $this->createStub(RunHistorySourceProviderInterface::class);
        $sources->method('historySource')->willReturn(new RunHistorySourceDTO($this->log, $this->path));
        $this->reader = new CanonicalToolInvocationReader($sources);
        $this->append('run_started', ['step_id' => 'model-step'], 0);
        $this->append('turn_advanced', ['turn_no' => 1, 'step_id' => 'model-step']);
    }

    public function testUnloadedShellRequestReceiptResultAndFailureUseCanonicalIndexWithoutStateReplay(): void
    {
        $call = $this->shell('command', true);
        $batches = $this->createStub(ToolBatchStoreInterface::class);
        $admission = $this->admission($batches);
        $container = self::getContainer();
        $paths = $this->createStub(ToolBatchRunStoragePathsInterface::class);
        $paths->method('resolveToolBatchesDirectory')->willReturn(\dirname($this->path).'/runtime/tool-batches');
        $paths->method('withStorage')->willReturnCallback(static fn (string $runId, callable $write): mixed => $write());
        $payloads = new SessionToolPayloadStore($paths, $container->get(SerializerInterface::class), $container->get(ValidatorInterface::class), $container->get(Filesystem::class));
        $deferred = $this->createStub(DeferredToolCompletionRepositoryInterface::class);
        $locks = $container->get(RunLockManager::class);
        $commands = new TestMessageBus();
        $middleware = new ToolPayloadMiddleware($payloads, $admission, $deferred, $locks, $container->get(ValidatorInterface::class), $commands, $this->logger, new CompleteDeferredToolCallHandler($deferred, $commands, $locks, $batches, $this->logger), \Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory::denormalizer());
        $receivedCalls = [];
        $handler = static function (ExecuteShellToolCall $message) use (&$receivedCalls): void {
            $receivedCalls[] = $message;
        };
        $bus = new MessageBus([$middleware, new HandleMessageMiddleware(new HandlersLocator([ExecuteShellToolCall::class => [$handler]]), true)]);
        $sent = $bus->dispatch($call);
        $reference = $sent->getMessage();
        $this->assertInstanceOf(ToolPayloadMessage::class, $reference);
        $received = $bus->dispatch($sent->with(new ReceivedStamp('tool')));
        $this->assertSame($reference, $received->getMessage());
        $this->assertEquals([$call], $receivedCalls);
        $result = new ToolCallResult('indexed', 1, 'shell-step', 1, $call->idempotencyKey(), $call->toolCallId, 0, ['tool_name' => 'bash', 'content' => [['type' => 'text', 'text' => 'done']], 'standalone' => true]);
        $this->assertInstanceOf(ToolPayloadMessage::class, $bus->dispatch($result)->getMessage());
        $payloads->deleteCall('indexed', $call->toolCallId);
        $bus->dispatch($sent->with(new ReceivedStamp('tool')));
        $this->assertCount(1, $commands->messages);
        $failure = $commands->messages[0];
        $this->assertInstanceOf(ToolCallResult::class, $failure);
        $this->assertTrue($failure->isError);
        $this->assertTrue($failure->result['standalone']);
        $this->assertArrayNotHasKey('arguments', $failure->result);
        $this->assertInstanceOf(ToolPayloadMessage::class, $bus->dispatch($failure)->getMessage());
        $this->assertFalse($admission->accepts($this->reference($call, 2), false));
        $this->append('tool_execution_end', ['tool_result' => ['tool_call_id' => $call->toolCallId]]);
        $this->assertFalse($admission->canPublish($call));
        $this->assertFalse($admission->accepts($reference->reference, false));
        $this->assertFalse($admission->canPublish($result));
    }

    public function testAttachedShellFailureKeepsModelOperationAndWarmLookupsReadNoHistorySuffix(): void
    {
        $call = $this->shell('attached', false);
        $admission = $this->admission($this->createStub(ToolBatchStoreInterface::class));
        $this->assertTrue($admission->canPublish($call));
        $this->logger->records = [];
        for ($i = 0; $i < 8; ++$i) {
            $this->assertTrue($admission->accepts($this->reference($call), false));
            $this->assertFalse($admission->failedInvocation($this->reference($call))->result['standalone']);
        }
        $reads = array_values(array_filter($this->logger->records, static fn (array $record): bool => 'history_index.physical_read' === $record['message']));
        $this->assertCount(16, $reads);
        foreach ($reads as $record) {
            $this->assertSame('index_boundary_validation', $record['context']['read_reason']);
            $this->assertFalse($record['context']['full_scan']);
            $this->assertLessThan(filesize($this->path), $record['context']['archive_bytes_read']);
        }
        $this->assertNull($this->reader->find('indexed', 'unknown'));
        $projection = self::getContainer()->get(RunOperationalProjectionRepository::class);
        $projection->replace(new RunState('indexed', RunStatus::Running, turnNo: 1, lastSeq: $this->seq, pendingShellToolCalls: [$call->toolCallId => true]));
        $this->assertSame(1, $projection->deleteForOwnerSession('indexed'));
        $this->assertNull($projection->findOperationalStatus('indexed'));
        (new Filesystem())->remove(\dirname($this->path).'/history-index.sqlite');
        $this->assertTrue($admission->canPublish($call));
    }

    public function testDeferredMembershipTracksAttemptsSettlementAndStepRetirement(): void
    {
        $this->append('llm_step_completed', ['assistant_message' => ['tool_calls' => [['id' => 'call']]]]);
        $reference = new ToolPayloadReferenceDTO('call', 'indexed', 1, 'model-step', 1, 'key', 'call', 0, 'subagent', str_repeat('a', 64), 100);
        $batches = $this->createStub(ToolBatchStoreInterface::class);
        $current = $reference;
        $batches->method('loadCallReference')->willReturnCallback(static function () use (&$current): ToolPayloadReferenceDTO {
            return $current;
        });
        $admission = $this->admission($batches);
        $this->assertTrue($admission->acceptsDeferred($reference, false));
        $approved = $this->approvedCall();
        $this->assertSame(1, $this->reader->find('indexed', 'call')?->attempt);
        $current = new ToolPayloadReferenceDTO('call', 'indexed', 1, 'model-step', $approved->attempt(), $approved->idempotencyKey(), 'call', 0, 'subagent', str_repeat('b', 64), 100);
        $this->assertFalse($admission->acceptsDeferred($reference, false));
        $this->assertTrue($admission->acceptsDeferred($current, false));
        $this->assertFalse($admission->acceptsDeferred(new ToolPayloadReferenceDTO('call', 'indexed', 1, 'model-step', 2, 'stale-key', 'call', 0, 'subagent', str_repeat('b', 64), 100), false));
        $this->assertFalse($admission->acceptsDeferred(new ToolPayloadReferenceDTO('call', 'indexed', 1, 'model-step', 2, $approved->idempotencyKey(), 'call', 1, 'subagent', str_repeat('b', 64), 100), false));
        $this->append('tool_execution_end', ['tool_result' => ['tool_call_id' => 'call']]);
        $this->assertNotNull($this->reader->find('indexed', 'call'));
        $this->append('tool_batch_committed');
        $this->assertFalse($admission->acceptsDeferred($current, false));
        $this->append('llm_step_completed', ['assistant_message' => ['tool_calls' => [['id' => 'next']]]]);
        $this->append('context_compaction_started', ['step_id' => 'compact']);
        $this->assertNull($this->reader->find('indexed', 'next'));
        $this->append('context_compacted');
        $this->assertNull($this->reader->find('indexed', 'next'));
    }

    public function testRetainedBranchesUsePredecessorsNotAnchorIntervals(): void
    {
        $this->append('turn_advanced', ['turn_no' => 2, 'step_id' => 'second'], 2);
        $old = $this->shell('branch-two', false, 2);
        $this->append('history_position_set', ['position_turn_no' => 1, 'reason' => 'restore'], 1);
        $this->append('turn_advanced', ['turn_no' => 3, 'step_id' => 'third'], 3);
        // Both branches are retained and branch two's anchor precedes three's.
        // Only one is an ancestor of the current selected turn.
        $this->assertNull($this->reader->find('indexed', $old->toolCallId));
        $current = $this->shell('branch-three', false, 3);
        $this->assertNotNull($this->reader->find('indexed', $current->toolCallId));
        $this->append('history_position_set', ['position_turn_no' => 1, 'reason' => 'history_select']);
        $this->assertNull($this->reader->find('indexed', $current->toolCallId));
        $this->append('history_tail_discarded', ['after_turn_no' => 1]);
        $this->append('turn_advanced', ['turn_no' => 2, 'step_id' => 'replacement'], 2);
        $replacement = $this->shell('replacement', true, 2);
        $this->assertNotNull($this->reader->find('indexed', $replacement->toolCallId));
        $this->assertNull($this->reader->find('indexed', $old->toolCallId));
        (new Filesystem())->remove(\dirname($this->path).'/history-index.sqlite');
        $this->assertNotNull($this->reader->find('indexed', $replacement->toolCallId));
        $this->assertNull($this->reader->find('indexed', $old->toolCallId));
    }

    public function testNewTurnRetiresPreviousOrdinaryIdentityEvenIfStepIdIsReused(): void
    {
        $this->append('llm_step_completed', ['assistant_message' => ['tool_calls' => [['id' => 'previous']]]]);
        $this->assertNotNull($this->reader->find('indexed', 'previous'));
        $this->append('turn_advanced', ['turn_no' => 2, 'step_id' => 'model-step'], 2);
        $this->assertNull($this->reader->find('indexed', 'previous'));
    }

    #[DataProvider('deferredProducerScenarios')]
    public function testUnloadedDeferredProducerPublishesActiveAndSettlesRetiredWithoutStateReplay(bool $approvalRetry): void
    {
        $this->append('llm_step_completed', ['assistant_message' => ['tool_calls' => [['id' => 'call']]]]);
        $call = $approvalRetry ? $this->approvedCall() : new ExecuteToolCall('indexed', 1, 'model-step', 1, 'key', 'call', 'subagent', [], 0);
        $reference = new ToolPayloadReferenceDTO('correlation', 'indexed', 1, 'model-step', $call->attempt(), $call->idempotencyKey(), 'call', 0, 'subagent', str_repeat('a', 64), 100);
        $resultReference = new ToolPayloadReferenceDTO('result', 'indexed', 1, 'model-step', $call->attempt(), $call->idempotencyKey(), 'call', 0, 'subagent', str_repeat('b', 64), 100);
        $batches = $this->createMock(ToolBatchStoreInterface::class);
        $batches->method('loadCallReference')->willReturn($reference);
        $batches->expects($this->once())->method('delete')->with('indexed', 1, 'model-step');
        $deferred = $this->createMock(DeferredToolCompletionRepositoryInterface::class);
        $deferred->method('findMetadataByDeferredId')->willReturn(new DeferredToolCompletionMetadataDTO('deferred', $reference, 'pending'));
        $deferred->expects($this->once())->method('findByDeferredId')->willReturn(new DeferredToolCompletionCorrelation('deferred', 'indexed', 1, 'model-step', $call->attempt(), $call->idempotencyKey(), 'call', 'subagent', [], 0));
        $deferred->expects($this->once())->method('markCompleted')->with('deferred');
        $payloads = $this->createMock(ToolPayloadStoreInterface::class);
        $payloads->expects($this->once())->method('publish')->willReturn($resultReference);
        $payloads->expects($this->never())->method('read');
        $commands = new TestMessageBus();
        $locks = self::getContainer()->get(RunLockManager::class);
        $middleware = new ToolPayloadMiddleware($payloads, $this->admission($batches), $deferred, $locks, self::getContainer()->get(ValidatorInterface::class), $commands, $this->logger, new CompleteDeferredToolCallHandler($deferred, $commands, $locks, $batches, $this->logger), \Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory::denormalizer());
        $bus = new MessageBus([$middleware]);
        $completion = new CompleteDeferredToolCall('deferred', [['type' => 'text', 'text' => 'done']]);
        $this->assertInstanceOf(ToolPayloadMessage::class, $bus->dispatch($completion)->getMessage());
        $this->append('tool_batch_committed');
        $this->assertNotNull($bus->dispatch($completion)->last(HandledStamp::class));
        $this->assertSame([], $commands->messages);
    }

    /** @return array<string, array{0: bool}> */
    public static function deferredProducerScenarios(): array
    {
        return ['initial invocation' => [false], 'approval retry' => [true]];
    }

    public function testUnpublishedShellCompletionCannotRetireInvocation(): void
    {
        $call = $this->shell('journal', true);
        $this->assertNotNull($this->reader->find('indexed', $call->toolCallId));
        $this->log->appendMany($this->path, [RunEvent::forAppend('indexed', 1, 'tool_execution_end', ['tool_result' => ['tool_call_id' => $call->toolCallId]])], work: ['run_id' => 'indexed', 'predecessor_seq' => $this->seq]);
        $this->assertNotNull($this->reader->find('indexed', $call->toolCallId));
        $pending = $this->log->verifiedPendingTransition($this->path, 'indexed');
        $this->assertNotNull($pending);
        $this->log->finalizeVerifiedTransition($this->path, 'indexed', $pending);
        $this->assertNull($this->reader->find('indexed', $call->toolCallId));
    }

    public function testMissingActiveBatchFailsClosedRatherThanUsingAbsentOperationalProjection(): void
    {
        $this->append('llm_step_completed', ['assistant_message' => ['tool_calls' => [['id' => 'call']]]]);
        $admission = $this->admission($this->createStub(ToolBatchStoreInterface::class));
        $reference = new ToolPayloadReferenceDTO('result', 'indexed', 1, 'model-step', 1, 'key', 'call', 0, 'subagent', str_repeat('a', 64), 100);
        $this->expectException(ToolPayloadException::class);
        $admission->accepts($reference, false);
    }

    public function testMalformedActiveShellMetadataFailsClosed(): void
    {
        $this->append('agent_command_applied', ['kind' => 'shell_command', 'idempotency_key' => 'broken', 'standalone' => true]);
        $this->expectException(\RuntimeException::class);
        $this->reader->find('indexed', 'sh_'.hash('sha256', 'broken'));
    }

    public function testMissingCanonicalArchiveCannotClassifyWorkAsObsolete(): void
    {
        $call = $this->shell('missing-archive', true);
        (new Filesystem())->remove($this->path);
        $this->expectException(\RuntimeException::class);
        $this->admission($this->createStub(ToolBatchStoreInterface::class))->canPublish($call);
    }

    private function approvedCall(): ExecuteToolCall
    {
        $call = new ExecuteToolCall('indexed', 1, 'model-step', 1, 'key', 'call', 'subagent', [], 0);
        $answer = new ToolCallHumanInputAnswerDTO('approval-question', true, ['run_id' => 'indexed', 'turn_no' => 1, 'step_id' => 'model-step', 'tool_call_id' => 'call'], []);
        $this->append('tool_execution_start', ['tool_call_id' => 'call', 'attempt' => 1]);
        // Approval resumes emit this command and a revised effect, not another start.
        $this->append('agent_command_applied', [
            'kind' => 'human_response', 'idempotency_key' => 'approval-command',
            'question_id' => $answer->questionId, 'answer' => $answer->answer,
            'continuation_kind' => 'tool_call', 'tool_call_id' => 'call', 'options' => [],
        ]);

        return $call->withAuthorizedHumanAnswer($answer);
    }

    private function admission(ToolBatchStoreInterface $batches): ToolPayloadAdmission
    {
        $replay = $this->createMock(RunStateRebuilderInterface::class);
        $replay->expects($this->never())->method('rebuildIfStale');
        $events = $this->createMock(EventStoreInterface::class);
        $events->expects($this->never())->method('allFor');
        $events->expects($this->never())->method('rangeFor');
        $events->expects($this->never())->method('reverseFor');
        $events->method('latestSequenceFor')->willReturnCallback(fn (): int => $this->seq);

        return new ToolPayloadAdmission($batches, new TestActiveRunContext(), $replay, $events, $this->logger, $this->reader);
    }

    private function shell(string $key, bool $standalone, int $turn = 1): ExecuteShellToolCall
    {
        $id = 'sh_'.hash('sha256', $key);
        $this->append('agent_command_applied', ['kind' => 'shell_command', 'idempotency_key' => $key, 'standalone' => $standalone, 'current_operation' => ['turn_no' => $turn, 'step_id' => 'shell-step', 'attempt' => 1, 'idempotency_key' => $key]], $turn);
        $this->append('tool_execution_start', ['tool_call_id' => $id, 'attempt' => 1], $turn);

        return new ExecuteShellToolCall('indexed', $turn, 'shell-step', 1, $id, 'true', $standalone);
    }

    private function reference(ExecuteShellToolCall $call, int $attempt = 1): ToolPayloadReferenceDTO
    {
        return new ToolPayloadReferenceDTO('shell', 'indexed', $call->turnNo(), 'shell-step', $attempt, $call->idempotencyKey(), $call->toolCallId, 0, 'bash', str_repeat('a', 64), 100);
    }

    /** @param array<string, mixed> $payload */
    private function append(string $type, array $payload = [], int $turn = 1): void
    {
        $record = self::getContainer()->get(EventPayloadNormalizer::class)->normalize('indexed', ++$this->seq, $turn, $type, $payload);
        file_put_contents($this->path, json_encode($record, \JSON_THROW_ON_ERROR)."\n", \FILE_APPEND);
    }
}
