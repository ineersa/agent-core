<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Repair;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\SessionRepairRefusalReasonEnum;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\Repair\SessionRepairService;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Messenger\MessageBusInterface;

#[Group('session-repair')]
final class SessionRepairServiceTest extends IsolatedKernelTestCase
{
    private const string TOOL_CALL_ID = 'call_00_abc';

    private const string STEP_ID = 'follow_up-xyz';

    private string $projectDir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('session-repair');
        TestDirectoryIsolation::ensureDirectory($this->projectDir.'/.hatfield/sessions');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testNoRepairNeededWhenSessionIsCleanlyTerminal(): void
    {
        $runId = '1';
        $factory = new EventFactory();
        $this->persistRunEvents($runId, $factory->eventsFromSpecs($runId, 1, 1, [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => ['messages' => []]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => 1, 'step_id' => 'follow_up-1']],
            ['type' => RunEventTypeEnum::AgentEnd->value, 'payload' => ['reason' => 'completed']],
        ]));

        $runStore = new TestActiveRunContext();
        $runStore->loadRecovered(new RunState(
            runId: $runId,
            status: RunStatus::Completed,
            version: 1,
            turnNo: 1,
            lastSeq: 3,
            model: 'test-model'));

        $service = $this->createService($runStore);
        $before = $this->readEvents($runId);

        $dryRun = $service->repair($runId, false, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertFalse($dryRun->repairableStaleCancellationDetected);
        $this->assertFalse($dryRun->staleCancellationRepaired);
        $this->assertStringContainsStringIgnoringCase('no repairable corruption', $dryRun->message);

        $apply = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertFalse($apply->repairableStaleCancellationDetected);
        $this->assertFalse($apply->staleCancellationRepaired);
        $this->assertSame($before, $this->readEvents($runId));
    }

    public function testRepairsFailedTerminalToolBatchWithMissingResult(): void
    {
        $runId = 'failed-tool-result';
        $turnNo = 7;
        $stepId = 'advance-after-tools-failed';
        $toolCallId = 'call-failed-result';
        $factory = new EventFactory();
        $this->persistRunEvents($runId, $factory->eventsFromSpecs($runId, $turnNo, 1, [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => ['messages' => []]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => $turnNo, 'step_id' => $stepId]],
            ['type' => RunEventTypeEnum::LlmStepCompleted->value, 'payload' => [
                'step_id' => $stepId,
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => $toolCallId,
                        'name' => 'bash',
                        'arguments' => ['command' => 'castor test'],
                        'order_index' => 0,
                    ]],
                ],
            ]],
            ['type' => RunEventTypeEnum::ToolExecutionStart->value, 'payload' => [
                'tool_call_id' => $toolCallId,
                'tool_name' => 'bash',
                'order_index' => 0,
                'mode' => 'parallel',
            ]],
            ['type' => RunEventTypeEnum::AgentEnd->value, 'payload' => [
                'reason' => 'failed',
                'error' => 'Handling ToolCallResult failed: Tool batch snapshot write failed.',
                'message_type' => ToolCallResult::class,
            ]],
        ]));

        $runStore = new TestActiveRunContext();
        $runStore->loadRecovered(new RunState(
            runId: $runId,
            status: RunStatus::Failed,
            version: 1,
            turnNo: $turnNo,
            lastSeq: 5,
            activeStepId: $stepId,
            model: 'test-model',
        ));
        $service = $this->createService($runStore);
        $before = $this->readEvents($runId);

        $dryRun = $service->repair($runId, false, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertTrue($dryRun->repairableStaleCancellationDetected);
        $this->assertFalse($dryRun->staleCancellationRepaired);
        $this->assertStringContainsString('failed session has unmatched assistant tool calls', $dryRun->message);
        $this->assertSame($before, $this->readEvents($runId));

        $applied = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertTrue($applied->staleCancellationRepaired);
        $this->assertStringContainsString('Failed session repaired', $applied->message);

        $events = $this->readEvents($runId);
        $this->assertSame(1, $this->countEvents($events, RunEventTypeEnum::ToolExecutionEnd->value));
        $this->assertSame(1, $this->countEvents($events, RunEventTypeEnum::ToolBatchCommitted->value));
        $this->assertSame(1, $this->countEvents($events, RunEventTypeEnum::AgentEnd->value));

        $messages = $this->replayMessages($runId);
        $this->assertCount(2, $messages);
        $this->assertSame('assistant', $messages[0]->role);
        $this->assertSame('tool', $messages[1]->role);
        $this->assertSame($toolCallId, $messages[1]->toolCallId);
        $this->assertTrue($messages[1]->isError);
        $this->assertStringContainsString('run failed before the result was committed', (string) ($messages[1]->content[0]['text'] ?? ''));
        $this->assertReplayStatus($runId, RunStatus::Failed);

        $lineCountAfterFirst = \count($this->readRawLines($runId));
        $second = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertFalse($second->repairableStaleCancellationDetected);
        $this->assertSame($lineCountAfterFirst, \count($this->readRawLines($runId)));
    }

    public function testCurrentLlmRepairPreservesInvocationIdentityOnNormalBus(): void
    {
        $runId = 'repair-llm';
        $stepId = 'step-repair';
        $key = hash('sha256', $runId.'|llm|1|'.$stepId);
        $factory = new EventFactory();
        $this->persistRunEvents($runId, $factory->eventsFromSpecs($runId, 1, 1, [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => ['payload' => ['messages' => []]]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => [
                'turn_no' => 1,
                'step_id' => $stepId,
                'operation_attempt' => 1,
                'operation_idempotency_key' => $key,
            ]],
        ]));
        $store = new TestActiveRunContext();
        $store->loadRecovered(new RunState(runId: $runId, status: RunStatus::Running, version: 1, turnNo: 1, lastSeq: 2, activeStepId: $stepId));
        $bus = new TestMessageBus();
        $service = $this->createService($store, dispatcherBus: $bus);

        $dryRun = $service->repair($runId, false, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertSame(0, $dryRun->activeOperationsRedriven);
        $this->assertSame([], $bus->messages);

        $applied = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertNull($applied->refusalReason);
        $this->assertSame(1, $applied->activeOperationsRedriven);
        $this->assertCount(1, $bus->messages);
        $request = $bus->messages[0];
        $this->assertInstanceOf(ExecuteLlmStep::class, $request);
        $this->assertSame($runId, $request->runId());
        $this->assertSame($stepId, $request->stepId());
        $this->assertSame($key, $request->idempotencyKey());
        $this->assertCount(2, $this->readEvents($runId));
    }

    public function testConflictingNormalizedCompactionEnvelopeIsRefusedWithoutDispatch(): void
    {
        $runId = 'repair-compaction-conflict';
        $key = 'compact-key';
        $request = new ExecuteCompactionStep(
            runId: 'other-run', turnNo: 4, stepId: 'compact-step', attempt: 1, idempotencyKey: $key,
            model: 'test-model', modelOptions: [], summarizationMessages: [], retainedTailMessages: [],
            messagesCompacted: 0, messagesRetained: 0, firstRetainedIndex: 0, tokenEstimateBefore: 0, trigger: 'auto',
        );
        $serializer = AttributeSerializerValidatorTestFactory::create()[0];
        $factory = new EventFactory();
        $this->persistRunEvents($runId, $factory->eventsFromSpecs($runId, 4, 1, [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => ['payload' => ['messages' => []]]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => 4, 'step_id' => 'step-4']],
            ['type' => RunEventTypeEnum::ContextCompactionStarted->value, 'payload' => [
                'turn_no' => 4,
                'step_id' => 'compact-step',
                'operation_attempt' => 1,
                'operation_idempotency_key' => $key,
                'worker_request' => $serializer->normalize($request),
            ]],
        ]));
        $store = new TestActiveRunContext();
        $store->loadRecovered(new RunState(runId: $runId, status: RunStatus::Compacting, version: 1, turnNo: 4, lastSeq: 3, activeStepId: 'compact-step'));
        $bus = new TestMessageBus();

        $result = $this->createService($store, dispatcherBus: $bus)->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());

        $this->assertSame(SessionRepairRefusalReasonEnum::AmbiguousPendingWork, $result->refusalReason);
        $this->assertSame([], $bus->messages);
    }

    public function testWaitingHumanToolBatchIsNotRedrivenOrMutated(): void
    {
        $runId = 'repair-waiting-human';
        $stepId = 'human-tool-step';
        $call = new ExecuteToolCall($runId, 3, $stepId, 1, 'human-tool-key', 'call-human', 'ask_human', [], 0);
        $batchStore = $this->createStub(ToolBatchStoreInterface::class);
        $batchStore->method('load')->willReturn(new ToolBatchStateDTO(
            expectedOrder: ['call-human' => 0],
            calls: ['call-human' => $call],
            pendingQueue: ['call-human'],
            inFlight: [],
            results: [],
            finalized: false,
            maxParallelism: 1,
            awaitingHumanInput: ['call-human' => 'question-1'],
        ));
        $this->persistActiveToolBatchEvents($runId, $stepId, waitingHuman: true);
        $store = new TestActiveRunContext();
        $store->loadRecovered(new RunState(runId: $runId, status: RunStatus::WaitingHuman, version: 1, turnNo: 3, lastSeq: 4, activeStepId: $stepId));
        $bus = new TestMessageBus();
        $service = $this->createService($store, dispatcherBus: $bus, toolBatchStore: $batchStore);
        $before = $this->readEvents($runId);

        $result = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertSame(SessionRepairRefusalReasonEnum::AmbiguousPendingWork, $result->refusalReason);
        $this->assertSame([], $bus->messages);
        $this->assertSame($before, $this->readEvents($runId));
    }

    public function testDryRunAndRepeatedApplyRedriveIdleRunningStateWithoutEvents(): void
    {
        $runId = 'repair-idle';
        $factory = new EventFactory();
        $this->persistRunEvents($runId, $factory->eventsFromSpecs($runId, 0, 1, [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => ['payload' => ['messages' => []]]],
        ]));
        $store = new TestActiveRunContext();
        $store->loadRecovered(new RunState(runId: $runId, status: RunStatus::Running, version: 1, lastSeq: 1));
        $bus = new TestMessageBus();
        $service = $this->createService($store, commandBus: $bus);
        $before = $this->readEvents($runId);
        $key = hash('sha256', $runId.'|repair-advance|0|1');

        $this->assertSame(0, $service->repair($runId, false, \Symfony\Component\Uid\Uuid::v4()->toRfc4122())->activeOperationsRedriven);
        $this->assertSame([], $bus->messages);

        $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertCount(2, $bus->messages);
        $this->assertContainsOnlyInstancesOf(AdvanceRun::class, $bus->messages);
        foreach ($bus->messages as $message) {
            $this->assertSame(0, $message->turnNo());
            $this->assertSame('repair-advance-0', $message->stepId());
            $this->assertSame(1, $message->attempt());
            $this->assertSame($key, $message->idempotencyKey());
        }
        $this->assertSame($before, $this->readEvents($runId));
    }

    public function testDryRunReportsStaleCancellation(): void
    {
        $runId = '2';
        $this->seedStaleCancellationHistory($runId, unresolvedTool: false);
        $runStore = $this->createStaleCancellationRunStore($runId, unresolvedTool: false);

        $service = $this->createService($runStore);
        $result = $service->repair($runId, false, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());

        $this->assertTrue($result->repairableStaleCancellationDetected);
        $this->assertStringContainsStringIgnoringCase('stale non-terminal cancellation', $result->message);
    }

    public function testApplyRepairsStaleCancellationAppendsTerminalEventsAndRebuildsCancelled(): void
    {
        $runId = '2';
        $this->seedStaleCancellationHistory($runId, unresolvedTool: false);
        $runStore = $this->createStaleCancellationRunStore($runId, unresolvedTool: false);
        $originalPrefix = $this->readRawLines($runId);

        $service = $this->createService($runStore);
        $result = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());

        $this->assertTrue($result->staleCancellationRepaired);

        $lines = $this->readRawLines($runId);
        $last = json_decode($lines[\count($lines) - 1], true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(RunEventTypeEnum::AgentEnd->value, $last['type']);
        $this->assertSame('cancelled', $last['payload']['reason'] ?? null);

        $this->assertContiguousSequences($lines);
        $this->assertReplayStatus($runId, RunStatus::Cancelled);

        for ($i = 0; $i < \count($originalPrefix); ++$i) {
            $this->assertSame($originalPrefix[$i], $lines[$i], \sprintf('Original line %d must be unchanged', $i + 1));
        }
    }

    public function testApplyRepairsStaleCancellationWithUnresolvedToolNeverCompleted(): void
    {
        $runId = '2';
        $this->seedStaleCancellationHistory($runId, unresolvedTool: true);
        $runStore = $this->createStaleCancellationRunStore($runId, unresolvedTool: true);

        $service = $this->createService($runStore);
        $result = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());

        $decoded = $this->readEvents($runId);
        $last = $decoded[\count($decoded) - 1];
        $this->assertSame(RunEventTypeEnum::AgentEnd->value, $last['type']);
        $this->assertSame('cancelled', $last['payload']['reason'] ?? null);

        $this->assertSame(1, $this->countEvents($decoded, RunEventTypeEnum::ToolExecutionEnd->value));

        foreach ($decoded as $row) {
            if (RunEventTypeEnum::ToolExecutionEnd->value !== $row['type']) {
                continue;
            }
            [$serializer] = AttributeSerializerValidatorTestFactory::create();
            $typedResult = (new ToolExecutionEndPayloadCodec($serializer))
                ->fromEventPayload($row['payload']);
            if (self::TOOL_CALL_ID !== $typedResult->toolCallId) {
                continue;
            }
            $this->assertSame($runId, $typedResult->runId());
            $this->assertSame(self::TOOL_CALL_ID, $typedResult->toolCallId);
            $this->assertSame('Tool execution cancelled by user.', $typedResult->result['content'][0]['text'] ?? null);
            $this->assertTrue($typedResult->isError);
            $this->assertSame('cancelled', $typedResult->error['type'] ?? null);
        }

        $replayed = $this->replayMessages($runId);
        $this->assertCount(2, $replayed);
        $this->assertSame('assistant', $replayed[0]->role);
        $this->assertSame('tool', $replayed[1]->role);
        $this->assertSame(self::TOOL_CALL_ID, $replayed[1]->toolCallId);

        $second = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertFalse($second->repairableStaleCancellationDetected);
        $this->assertSame(1, $this->countEvents($this->readEvents($runId), RunEventTypeEnum::ToolExecutionEnd->value));

        $this->assertReplayStatus($runId, RunStatus::Cancelled);
    }

    public function testRepairIsIdempotent(): void
    {
        $runId = '2';
        $this->seedStaleCancellationHistory($runId, unresolvedTool: false);
        $runStore = $this->createStaleCancellationRunStore($runId, unresolvedTool: false);

        $service = $this->createService($runStore);
        $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());

        $lineCountAfterFirst = \count($this->readRawLines($runId));

        $second = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertFalse($second->repairableStaleCancellationDetected);
        $this->assertSame($lineCountAfterFirst, \count($this->readRawLines($runId)));
    }

    public function testRepairNeverEditsOrReordersExistingEvents(): void
    {
        $runId = '2';
        $this->seedStaleCancellationHistory($runId, unresolvedTool: false);
        $original = $this->readRawLines($runId);

        $runStore = $this->createStaleCancellationRunStore($runId, unresolvedTool: false);

        $service = $this->createService($runStore);
        $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());

        $lines = $this->readRawLines($runId);
        foreach ($original as $index => $expectedLine) {
            $this->assertSame($expectedLine, $lines[$index], \sprintf('Line %d must be byte-identical', $index + 1));
            $payload = json_decode($expectedLine, true, 512, \JSON_THROW_ON_ERROR);
            $this->assertSame($payload['seq'], json_decode($lines[$index], true, 512, \JSON_THROW_ON_ERROR)['seq']);
        }
    }

    public function testDuplicateSequencesProducesRefusal(): void
    {
        $runId = 'dup';
        $factory = new EventFactory();
        $this->persistRunEvents($runId, [
            $factory->event($runId, 1, 0, RunEventTypeEnum::RunStarted->value, []),
            $factory->event($runId, 2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $factory->event($runId, 3, 1, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
            $factory->event($runId, 3, 1, RunEventTypeEnum::AgentCommandApplied->value, ['kind' => 'cancel']),
        ]);

        $runStore = new TestActiveRunContext();
        $runStore->loadRecovered(RunState::queued($runId));

        $service = $this->createService($runStore);
        $before = $this->readRawLines($runId);
        $result = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());

        $this->assertFalse($result->repairableStaleCancellationDetected);
        $this->assertSame(SessionRepairRefusalReasonEnum::DuplicateSequences, $result->refusalReason);
        $this->assertStringContainsStringIgnoringCase('duplicate', $result->message);
        $this->assertSame($before, $this->readRawLines($runId));
    }

    public function testActiveStreamingProducesRefusal(): void
    {
        $runId = 'stream';
        $factory = new EventFactory();
        $this->persistRunEvents($runId, $factory->eventsFromSpecs($runId, 1, 1, [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => []],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => 1, 'step_id' => 'llm-1']],
        ]));

        $runStore = new TestActiveRunContext();
        $runStore->loadRecovered(new RunState(
            runId: $runId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 2,
            isStreaming: true,
            streamingMessage: ['message_id' => 'm1'],
            activeStepId: 'llm-1',
            model: 'test-model'));

        $service = $this->createService($runStore);
        $before = $this->readRawLines($runId);
        $result = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());

        $this->assertSame(SessionRepairRefusalReasonEnum::ActiveStreaming, $result->refusalReason);
        $this->assertStringContainsStringIgnoringCase('active streaming', $result->message);
        $this->assertSame($before, $this->readRawLines($runId));
    }

    public function testAbandonedAllocationGapIsAcceptedByRepair(): void
    {
        $runId = 'abandoned-allocation';
        $factory = new EventFactory();
        $this->persistRunEvents($runId, [
            $factory->event($runId, 1, 0, RunEventTypeEnum::RunStarted->value, []),
            $factory->event($runId, 2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $factory->event($runId, 4, 1, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
        ]);

        $runStore = new TestActiveRunContext();
        $runStore->loadRecovered(new RunState(
            runId: $runId,
            status: RunStatus::Completed,
            version: 1,
            turnNo: 1,
            lastSeq: 4,
            model: 'test-model'));

        $service = $this->createService($runStore);
        $before = $this->readRawLines($runId);
        $preview = $service->repair($runId, false, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertNull($preview->refusalReason);
        $this->assertStringContainsStringIgnoringCase('no repairable corruption', $preview->message);

        $apply = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertNull($apply->refusalReason);
        $this->assertFalse($apply->staleCancellationRepaired);
        $this->assertSame($before, $this->readRawLines($runId));
        $this->assertSame([1, 2, 4], array_map(
            static fn (array $event): int => $event['seq'],
            $this->readEvents($runId),
        ));
    }

    public function testAmbiguousPendingWorkProducesTypedRefusal(): void
    {
        $runId = 'ambiguous';
        $events = $this->buildCanonicalToolTurnPrefix($runId, includeToolStart: true, includeCompletedToolGroup: false);
        $this->persistRunEvents($runId, $events);

        $runStore = new TestActiveRunContext();
        $runStore->loadRecovered(new RunState(
            runId: $runId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 33,
            lastSeq: \count($events),
            pendingToolCalls: [self::TOOL_CALL_ID => false],
            activeStepId: self::STEP_ID,
            model: 'test-model'));

        $service = $this->createService($runStore);
        $before = $this->readRawLines($runId);
        $result = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());

        $this->assertSame(SessionRepairRefusalReasonEnum::AmbiguousPendingWork, $result->refusalReason);
        $this->assertSame($before, $this->readRawLines($runId));
    }

    public function testIncompleteLlmPhaseReceivesLlmStepAbortedOnCancellationRepair(): void
    {
        $runId = 'llm-incomplete';
        $factory = new EventFactory();
        $turnNo = 33;
        $events = $factory->eventsFromSpecs($runId, $turnNo, 1, [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => ['messages' => []]],
            ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => ['kind' => 'follow_up', 'payload' => ['text' => 'continue']]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => $turnNo, 'step_id' => self::STEP_ID]],
            ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => ['kind' => 'cancel']],
            ['type' => RunEventTypeEnum::AgentCommandRejected->value, 'payload' => ['reason' => 'Command "follow_up" rejected because cancellation is in progress.']],
        ]);
        $this->persistRunEvents($runId, $events);

        $runStore = new TestActiveRunContext();
        $runStore->loadRecovered(new RunState(
            runId: $runId,
            status: RunStatus::Cancelling,
            version: 1,
            turnNo: $turnNo,
            lastSeq: \count($events),
            activeStepId: self::STEP_ID,
            model: 'test-model'));

        $service = $this->createService($runStore);
        $prefix = \count($events);
        $result = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertTrue($result->staleCancellationRepaired);

        $decoded = $this->readEvents($runId);
        $appended = \array_slice($decoded, $prefix);
        $this->assertSame(1, $this->countInSlice($appended, RunEventTypeEnum::LlmStepAborted->value));
        $this->assertSame(1, $this->countInSlice($appended, RunEventTypeEnum::AgentEnd->value));
        $this->assertReplayStatus($runId, RunStatus::Cancelled);
    }

    public function testToolPhaseAfterLlmStepCompletedDoesNotReceiveSyntheticLlmStepAborted(): void
    {
        $runId = 'tool-phase';
        $this->seedStaleCancellationHistory($runId, unresolvedTool: true);
        $runStore = $this->createStaleCancellationRunStore($runId, unresolvedTool: true);
        $prefix = \count($this->readRawLines($runId));

        $service = $this->createService($runStore);
        $result = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $this->assertTrue($result->staleCancellationRepaired);

        $decoded = $this->readEvents($runId);
        $appended = \array_slice($decoded, $prefix);
        $this->assertSame(0, $this->countInSlice($appended, RunEventTypeEnum::LlmStepAborted->value));
        $this->assertGreaterThanOrEqual(1, $this->countInSlice($appended, RunEventTypeEnum::ToolExecutionEnd->value));
    }

    public function testNoEventsRefusalLogsStructuredRefusal(): void
    {
        $runId = 'no-events';
        $logger = new TestLogger();
        $service = $this->createService(logger: $logger);
        $result = $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());

        $this->assertSame(SessionRepairRefusalReasonEnum::NoEvents, $result->refusalReason);
        $this->assertCount(1, $logger->records);
        $this->assertSame('session_repair.refused', $logger->records[0]['message']);
        $this->assertSame('no_events', $logger->records[0]['context']['refusal_reason']);
        $this->assertSame($runId, $logger->records[0]['context']['run_id']);
    }

    public function testMultiTurnLlmAbortTargetsOnlyLatestIncompletePhase(): void
    {
        $runId = 'multi-turn-llm';
        $factory = new EventFactory();
        $firstStep = 'follow_up-first';
        $secondStep = 'follow_up-second';
        $events = $factory->eventsFromSpecs($runId, 33, 1, [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => ['messages' => []]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => 1, 'step_id' => $firstStep]],
            ['type' => RunEventTypeEnum::LlmStepCompleted->value, 'payload' => ['step_id' => $firstStep, 'assistant_message' => ['role' => 'assistant', 'content' => 'done']]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => 33, 'step_id' => $secondStep]],
            ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => ['kind' => 'cancel']],
        ]);
        $this->persistRunEvents($runId, $events);

        $runStore = new TestActiveRunContext();
        $runStore->loadRecovered(new RunState(
            runId: $runId,
            status: RunStatus::Cancelling,
            version: 1,
            turnNo: 33,
            lastSeq: \count($events),
            activeStepId: $secondStep,
            model: 'test-model'));

        $service = $this->createService($runStore);
        $prefix = \count($events);
        $service->repair($runId, true, \Symfony\Component\Uid\Uuid::v4()->toRfc4122());
        $decoded = $this->readEvents($runId);
        $appended = \array_slice($decoded, $prefix);
        $this->assertSame(1, $this->countInSlice($appended, RunEventTypeEnum::LlmStepAborted->value));
        $abort = null;
        foreach ($appended as $row) {
            if (RunEventTypeEnum::LlmStepAborted->value === $row['type']) {
                $abort = $row;
            }
        }
        $this->assertNotNull($abort);
        $this->assertSame($secondStep, $abort['payload']['step_id'] ?? null);
    }

    private function persistRunEvents(string $runId, array $events): void
    {
        $normalizer = new EventPayloadNormalizer();
        $lines = [];
        foreach ($events as $event) {
            $lines[] = json_encode($normalizer->normalizeRunEvent($event), \JSON_THROW_ON_ERROR);
        }
        $this->writeEvents($runId, $lines);
    }

    private function seedStaleCancellationHistory(string $runId, bool $unresolvedTool): void
    {
        $events = $this->buildStaleCancellationEvents($runId, $unresolvedTool);
        $this->persistRunEvents($runId, $events);
    }

    /**
     * @return list<RunEvent>
     */
    private function buildStaleCancellationEvents(string $runId, bool $unresolvedTool): array
    {
        $turnNo = 33;
        $factory = new EventFactory();
        $specs = [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => ['messages' => []]],
            ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => ['kind' => 'follow_up', 'payload' => ['text' => 'run subagent']]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => $turnNo, 'step_id' => 'follow_up-abc']],
            ['type' => RunEventTypeEnum::LlmStepCompleted->value, 'payload' => [
                'step_id' => self::STEP_ID,
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => self::TOOL_CALL_ID,
                        'type' => 'function',
                        'function' => ['name' => 'subagent', 'arguments' => '{}'],
                    ]],
                ],
            ]],
        ];

        if ($unresolvedTool) {
            $specs[] = [
                'type' => RunEventTypeEnum::ToolExecutionStart->value,
                'payload' => [
                    'tool_call_id' => self::TOOL_CALL_ID,
                    'tool_name' => 'subagent',
                    'order_index' => 0,
                    'mode' => 'async',
                    'step_id' => self::STEP_ID,
                ],
            ];
        } else {
            $specs = array_merge($specs, $this->canonicalCompletedToolGroupSpecs(
                runId: $runId,
                turnNo: $turnNo,
                stepId: self::STEP_ID,
                toolCallId: self::TOOL_CALL_ID,
                toolName: 'subagent',
                orderIndex: 0,
                resultText: 'done',
                isError: false,
            ));
        }

        $specs[] = ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => ['kind' => 'cancel']];
        $specs[] = ['type' => RunEventTypeEnum::AgentCommandRejected->value, 'payload' => ['reason' => 'Command "follow_up" rejected because cancellation is in progress.']];

        return $factory->eventsFromSpecs($runId, $turnNo, 1, $specs);
    }

    /**
     * @return list<RunEvent>
     */
    private function buildCanonicalToolTurnPrefix(string $runId, bool $includeToolStart, bool $includeCompletedToolGroup): array
    {
        $turnNo = 33;
        $factory = new EventFactory();
        $specs = [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => ['messages' => []]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => $turnNo, 'step_id' => self::STEP_ID]],
            ['type' => RunEventTypeEnum::LlmStepCompleted->value, 'payload' => [
                'step_id' => self::STEP_ID,
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => self::TOOL_CALL_ID,
                        'type' => 'function',
                        'function' => ['name' => 'read', 'arguments' => '{}'],
                    ]],
                ],
            ]],
        ];

        if ($includeToolStart) {
            $specs[] = [
                'type' => RunEventTypeEnum::ToolExecutionStart->value,
                'payload' => [
                    'tool_call_id' => self::TOOL_CALL_ID,
                    'tool_name' => 'read',
                    'order_index' => 0,
                    'mode' => 'async',
                    'step_id' => self::STEP_ID,
                ],
            ];
        }

        if ($includeCompletedToolGroup) {
            $specs = array_merge($specs, $this->canonicalCompletedToolGroupSpecs(
                runId: $runId,
                turnNo: $turnNo,
                stepId: self::STEP_ID,
                toolCallId: self::TOOL_CALL_ID,
                toolName: 'read',
                orderIndex: 0,
                resultText: 'file content',
                isError: false,
            ));
        }

        return $factory->eventsFromSpecs($runId, $turnNo, 1, $specs);
    }

    /**
     * @return list<array{type: string, payload: array<string, mixed>}>
     */
    private function canonicalCompletedToolGroupSpecs(
        string $runId,
        int $turnNo,
        string $stepId,
        string $toolCallId,
        string $toolName,
        int $orderIndex,
        string $resultText,
        bool $isError,
    ): array {
        $normalizer = new AgentMessageNormalizer();
        $toolResult = new ToolCallResult(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', $toolCallId.'-result'),
            toolCallId: $toolCallId,
            orderIndex: $orderIndex,
            result: [
                'tool_name' => $toolName,
                'content' => [['type' => 'text', 'text' => $resultText]],
            ],
            isError: $isError,
            error: $isError ? ['type' => 'error', 'message' => $resultText] : null,
        );

        return [
            [
                'type' => RunEventTypeEnum::ToolExecutionStart->value,
                'payload' => [
                    'tool_call_id' => $toolCallId,
                    'tool_name' => $toolName,
                    'order_index' => $orderIndex,
                    'mode' => 'async',
                    'step_id' => $stepId,
                ],
            ],
            [
                'type' => RunEventTypeEnum::ToolExecutionEnd->value,
                'payload' => (new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()))->toEventPayload($toolResult),
            ],
            [
                'type' => RunEventTypeEnum::ToolBatchCommitted->value,
                'payload' => [
                    'count' => 1,
                    'turn_no' => $turnNo,
                    'step_id' => $stepId,
                ],
            ],
        ];
    }

    /**
     * @param list<string> $lines
     */
    private function writeEvents(string $runId, array $lines): void
    {
        $dir = $this->projectDir.'/.hatfield/sessions/'.$runId;
        TestDirectoryIsolation::ensureDirectory($dir);
        file_put_contents($dir.'/events.jsonl', implode("\n", $lines)."\n");
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function readEvents(string $runId): array
    {
        $decoded = [];
        foreach ($this->readRawLines($runId) as $line) {
            $decoded[] = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        }

        return $decoded;
    }

    /**
     * @return list<string>
     */
    private function readRawLines(string $runId): array
    {
        $path = $this->projectDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        $contents = file_get_contents($path);
        $this->assertNotFalse($contents);

        $lines = [];
        foreach (explode("\n", $contents) as $line) {
            $trimmed = trim($line);
            if ('' !== $trimmed) {
                $lines[] = $trimmed;
            }
        }

        return $lines;
    }

    private function persistActiveToolBatchEvents(string $runId, string $stepId, bool $waitingHuman = false): void
    {
        $factory = new EventFactory();
        $specs = [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => ['payload' => ['messages' => []]]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => 3, 'step_id' => $stepId]],
            ['type' => RunEventTypeEnum::LlmStepCompleted->value, 'payload' => ['assistant_message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [['id' => 'call-pending', 'type' => 'function', 'function' => ['name' => 'read', 'arguments' => '{}']]],
            ]]],
        ];
        if ($waitingHuman) {
            $specs[] = ['type' => RunEventTypeEnum::WaitingHuman->value, 'payload' => [
                'kind' => 'tool_call',
                'tool_call_id' => 'call-human',
                'tool_name' => 'ask_human',
                'question_id' => 'question-1',
                'prompt' => 'Continue?',
                'schema' => ['type' => 'string'],
                'continuation_ref' => [],
            ]];
        }

        $this->persistRunEvents($runId, $factory->eventsFromSpecs($runId, 3, 1, $specs));
    }

    private function createService(?ActiveRunContextInterface $activeRunContext = null, ?TestLogger $logger = null, ?TestMessageBus $dispatcherBus = null, ?ToolBatchStoreInterface $toolBatchStore = null, ?MessageBusInterface $commandBus = null): SessionRepairService
    {
        $activeRunContext ??= new TestActiveRunContext();
        $dispatcherBus ??= new TestMessageBus();
        $commandBus ??= new TestMessageBus();
        $toolBatchStore ??= $this->createStub(ToolBatchStoreInterface::class);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );

        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );

        $lockDir = $this->projectDir.'/.hatfield/locks';
        TestDirectoryIsolation::ensureDirectory($lockDir);

        $eventStore = new SessionRunEventStore(
            hatfieldSessionStore: $hatfieldSessionStore,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore($lockDir)),
            logger: new NullLogger(),
            sequenceAllocator: new FileRunSequenceAllocator(),
        );

        return new SessionRepairService(
            eventStore: $eventStore,
            activeRunContext: $activeRunContext,
            runStateReducer: new RunStateReducer(AttributeSerializerValidatorTestFactory::denormalizer(), new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())),
            replayEventPreparer: new ReplayEventPreparer(),
            eventFactory: new EventFactory(),
            toolCallSequenceValidator: new AgentMessageToolCallSequenceValidator(),
            lockManager: new RunLockManager(new LockFactory(new FlockStore($lockDir))),
            logger: $logger ?? new NullLogger(),
            toolBatchStore: $toolBatchStore,
            serializer: AttributeSerializerValidatorTestFactory::create()[0],
            historyReplayFilter: new \Ineersa\CodingAgent\Session\History\HistoryReplayFilter(new \Ineersa\CodingAgent\Session\History\HistoryProjector()),
            runCommit: new \Ineersa\AgentCore\Application\Pipeline\RunCommit(
                activeRunContext: $activeRunContext,
                eventStore: $eventStore,
                logger: new NullLogger(),
                finalizer: \Ineersa\AgentCore\Tests\Support\TestTransitionFinalizerFactory::create($eventStore, new StepDispatcher($commandBus, $dispatcherBus, new TestLogger())),
                actionValidator: new \Ineersa\AgentCore\Application\Handler\CoordinationActionValidator(),
            ),
            deferredBatches: self::getContainer()->get(\Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository::class),
        );
    }

    private function createStaleCancellationRunStore(string $runId, bool $unresolvedTool): TestActiveRunContext
    {
        $runStore = new TestActiveRunContext();
        $eventCount = \count($this->buildStaleCancellationEvents($runId, $unresolvedTool));
        $runStore->loadRecovered(new RunState(
            runId: $runId,
            status: RunStatus::Cancelling,
            version: 1,
            turnNo: 33,
            lastSeq: $eventCount,
            pendingToolCalls: $unresolvedTool ? [self::TOOL_CALL_ID => false] : [self::TOOL_CALL_ID => true],
            activeStepId: self::STEP_ID,
            model: 'test-model'));

        return $runStore;
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private function countEvents(array $events, string $type, ?string $toolCallId = null, ?string $role = null): int
    {
        $count = 0;
        foreach ($events as $row) {
            if (($row['type'] ?? null) !== $type) {
                continue;
            }
            if (null !== $toolCallId && ($row['payload']['tool_call_id'] ?? null) !== $toolCallId) {
                continue;
            }
            if (null !== $role && ($row['payload']['message_role'] ?? null) !== $role) {
                continue;
            }
            ++$count;
        }

        return $count;
    }

    /**
     * @param list<array<string, mixed>> $slice
     */
    private function countInSlice(array $slice, string $type, ?string $toolCallId = null): int
    {
        return $this->countEvents($slice, $type, $toolCallId);
    }

    /**
     * @return list<AgentMessage>
     */
    private function replayMessages(string $runId): array
    {
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
        $lockDir = $this->projectDir.'/.hatfield/locks';
        TestDirectoryIsolation::ensureDirectory($lockDir);
        $eventStore = new SessionRunEventStore(
            hatfieldSessionStore: $hatfieldSessionStore,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore($lockDir)),
            logger: new NullLogger(),
            sequenceAllocator: new FileRunSequenceAllocator(),
        );

        $events = $eventStore->allFor($runId);
        $replayed = (new RunStateReducer(AttributeSerializerValidatorTestFactory::denormalizer(), new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())))->replay(RunState::queued($runId), $events);

        return $replayed->messages;
    }

    private function assertReplayStatus(string $runId, RunStatus $expected): void
    {
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
        $lockDir = $this->projectDir.'/.hatfield/locks';
        TestDirectoryIsolation::ensureDirectory($lockDir);
        $eventStore = new SessionRunEventStore(
            hatfieldSessionStore: $hatfieldSessionStore,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore($lockDir)),
            logger: new NullLogger(),
            sequenceAllocator: new FileRunSequenceAllocator(),
        );

        $events = $eventStore->allFor($runId);
        $replayed = (new RunStateReducer(AttributeSerializerValidatorTestFactory::denormalizer(), new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer())))->replay(RunState::queued($runId), $events);
        $this->assertSame($expected, $replayed->status);
    }

    /**
     * @param list<string> $lines
     */
    private function assertContiguousSequences(array $lines): void
    {
        $expected = 1;
        foreach ($lines as $line) {
            $payload = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            $this->assertSame($expected, $payload['seq']);
            ++$expected;
        }
    }
}
