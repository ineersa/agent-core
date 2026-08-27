<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Repair;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageToolCallSequenceValidator;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\Repair\SessionRepairRefusalReasonEnum;
use Ineersa\CodingAgent\Session\Repair\SessionRepairService;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[Group('session-repair')]
final class SessionRepairServiceTest extends TestCase
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
            ['type' => RunEventTypeEnum::AgentStart->value, 'payload' => ['messages' => []]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => 1, 'step_id' => 'follow_up-1']],
            ['type' => RunEventTypeEnum::AgentEnd->value, 'payload' => ['reason' => 'completed']],
        ]));

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $runId,
            status: RunStatus::Completed,
            version: 1,
            turnNo: 1,
            lastSeq: 3,
            model: 'test-model'), 0);

        $service = $this->createService($runStore);
        $before = $this->readEvents($runId);

        $dryRun = $service->repair($runId, false);
        $this->assertFalse($dryRun->repairableStaleCancellationDetected);
        $this->assertFalse($dryRun->staleCancellationRepaired);
        $this->assertStringContainsStringIgnoringCase('no repairable corruption', $dryRun->message);

        $apply = $service->repair($runId, true);
        $this->assertFalse($apply->repairableStaleCancellationDetected);
        $this->assertFalse($apply->staleCancellationRepaired);
        $this->assertSame($before, $this->readEvents($runId));
    }

    public function testDryRunDoesNotDispatchAndApplyRedrivesCurrentLlmWithSameIdentity(): void
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
        $store = new InMemoryRunStore();
        $store->compareAndSwap(new RunState(runId: $runId, status: RunStatus::Running, version: 1, turnNo: 1, lastSeq: 2, activeStepId: $stepId), 0);
        $bus = new TestMessageBus();
        $service = $this->createService($store, dispatcherBus: $bus);

        $dryRun = $service->repair($runId, false);
        $this->assertSame(0, $dryRun->activeOperationsRedriven);
        $this->assertSame([], $bus->messages);

        $applied = $service->repair($runId, true);
        $this->assertSame(1, $applied->activeOperationsRedriven);
        $this->assertCount(1, $bus->messages);
        $this->assertInstanceOf(ExecuteLlmStep::class, $bus->messages[0]);
        $this->assertSame($key, $bus->messages[0]->idempotencyKey());

        $service->repair($runId, true);
        $this->assertCount(2, $bus->messages);
        $this->assertSame($key, $bus->messages[1]->idempotencyKey());
        $this->assertCount(2, $this->readEvents($runId));
    }

    public function testDryRunAndRepeatedApplyRedriveCurrentCompactionWithSamePayload(): void
    {
        $runId = 'repair-compaction';
        $key = 'compact-key';
        $factory = new EventFactory();
        $workerRequest = new ExecuteCompactionStep(
            runId: $runId,
            turnNo: 4,
            stepId: 'compact-step',
            attempt: 2,
            idempotencyKey: $key,
            model: 'test-model',
            modelOptions: ['thinking_level' => 'low'],
            summarizationMessages: [new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'old']])],
            retainedTailMessages: [new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'new']])],
            messagesCompacted: 1,
            messagesRetained: 1,
            firstRetainedIndex: 1,
            tokenEstimateBefore: 42,
            trigger: 'auto',
            continueAfterCompaction: true,
        );
        $serializer = AttributeSerializerValidatorTestFactory::create()[0];
        $this->persistRunEvents($runId, $factory->eventsFromSpecs($runId, 4, 1, [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => ['payload' => ['messages' => []]]],
            ['type' => RunEventTypeEnum::ContextCompactionStarted->value, 'payload' => [
                'turn_no' => 4,
                'step_id' => 'compact-step',
                'operation_attempt' => 2,
                'operation_idempotency_key' => $key,
                'worker_request' => $serializer->normalize($workerRequest),
            ]],
        ]));
        $store = new InMemoryRunStore();
        $store->compareAndSwap(new RunState(runId: $runId, status: RunStatus::Compacting, version: 1, turnNo: 4, lastSeq: 2, activeStepId: 'compact-step'), 0);
        $bus = new TestMessageBus();
        $service = $this->createService($store, dispatcherBus: $bus);
        $before = $this->readEvents($runId);

        $this->assertSame(0, $service->repair($runId, false)->activeOperationsRedriven);
        $this->assertSame([], $bus->messages);
        $this->assertSame($before, $this->readEvents($runId));

        $service->repair($runId, true);
        $service->repair($runId, true);
        $this->assertCount(2, $bus->messages);
        $this->assertContainsOnlyInstancesOf(ExecuteCompactionStep::class, $bus->messages);
        $this->assertSame($key, $bus->messages[0]->idempotencyKey());
        $this->assertSame($key, $bus->messages[1]->idempotencyKey());
        $this->assertSame(2, $bus->messages[0]->attempt());
        $this->assertSame('old', $bus->messages[0]->summarizationMessages[0]->content[0]['text']);
        $this->assertSame('new', $bus->messages[0]->retainedTailMessages[0]->content[0]['text']);
        $this->assertSame($before, $this->readEvents($runId));
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
            ['type' => RunEventTypeEnum::ContextCompactionStarted->value, 'payload' => [
                'turn_no' => 4,
                'step_id' => 'compact-step',
                'operation_attempt' => 1,
                'operation_idempotency_key' => $key,
                'worker_request' => $serializer->normalize($request),
            ]],
        ]));
        $store = new InMemoryRunStore();
        $store->compareAndSwap(new RunState(runId: $runId, status: RunStatus::Compacting, version: 1, turnNo: 4, lastSeq: 2, activeStepId: 'compact-step'), 0);
        $bus = new TestMessageBus();

        $result = $this->createService($store, dispatcherBus: $bus)->repair($runId, true);

        $this->assertSame(SessionRepairRefusalReasonEnum::AmbiguousPendingWork, $result->refusalReason);
        $this->assertSame([], $bus->messages);
    }

    public function testDryRunAndRepeatedApplyRedriveAttachedShellFromCanonicalCommand(): void
    {
        $runId = 'repair-shell';
        $key = 'shell-command-key';
        $toolCallId = 'sh_'.hash('sha256', $key);
        $factory = new EventFactory();
        $this->persistRunEvents($runId, $factory->eventsFromSpecs($runId, 2, 1, [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => ['payload' => ['messages' => []]]],
            ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => [
                'kind' => 'shell_command',
                'text' => '!printf repair-shell',
                'idempotency_key' => $key,
                'standalone' => false,
                'current_operation' => AttributeSerializerValidatorTestFactory::create()[0]->normalize(
                    new CurrentOperationDTO(2, 'llm-step', 1, 'llm-key'),
                ),
            ]],
        ]));
        $store = new InMemoryRunStore();
        $store->compareAndSwap(new RunState(runId: $runId, status: RunStatus::Running, version: 1, lastSeq: 2), 0);
        $bus = new TestMessageBus();
        $service = $this->createService($store, dispatcherBus: $bus);
        $before = $this->readEvents($runId);

        $this->assertSame(0, $service->repair($runId, false)->activeOperationsRedriven);
        $this->assertSame([], $bus->messages);

        $service->repair($runId, true);
        $service->repair($runId, true);
        $this->assertCount(2, $bus->messages);
        $this->assertContainsOnlyInstancesOf(ExecuteShellToolCall::class, $bus->messages);
        foreach ($bus->messages as $message) {
            $this->assertSame($toolCallId, $message->toolCallId);
            $this->assertSame('printf repair-shell', $message->commandText);
            $this->assertSame(hash('sha256', $runId.'|'.$toolCallId), $message->idempotencyKey());
            $this->assertFalse($message->standalone);
        }
        $this->assertSame($before, $this->readEvents($runId));
    }

    public function testHistoricalShellWithoutStandaloneEvidenceIsRefusedWithoutDispatch(): void
    {
        $runId = 'repair-historical-shell';
        $key = 'historical-shell-key';
        $factory = new EventFactory();
        $this->persistRunEvents($runId, $factory->eventsFromSpecs($runId, 0, 1, [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => ['payload' => ['messages' => []]]],
            ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => [
                'kind' => 'shell_command',
                'text' => '!printf historical-shell',
                'idempotency_key' => $key,
            ]],
        ]));
        $store = new InMemoryRunStore();
        $store->compareAndSwap(new RunState(runId: $runId, status: RunStatus::Running, version: 1, lastSeq: 2), 0);
        $bus = new TestMessageBus();
        $service = $this->createService($store, dispatcherBus: $bus);

        $result = $service->repair($runId, true);

        $this->assertSame(SessionRepairRefusalReasonEnum::AmbiguousPendingWork, $result->refusalReason);
        $this->assertSame([], $bus->messages);
    }

    /**
     * @return iterable<string, array{childTurn: bool}>
     */
    public static function standaloneShellRepairCases(): iterable
    {
        yield 'queued' => ['childTurn' => false];
        yield 'terminal_child_turn' => ['childTurn' => true];
    }

    #[DataProvider('standaloneShellRepairCases')]
    public function testApplyRedrivesStandaloneShellFromCanonicalIdentityWithoutLlm(bool $childTurn): void
    {
        $runId = $childTurn ? 'repair-terminal-shell' : 'repair-queued-shell';
        $key = $childTurn ? 'terminal-shell-key' : 'queued-shell-key';
        $stepId = $childTurn ? 'terminal-shell-step' : 'queued-shell-step';
        $turnNo = $childTurn ? 2 : 0;
        $toolCallId = 'sh_'.hash('sha256', $key);
        $factory = new EventFactory();
        $specs = [
            ['type' => RunEventTypeEnum::RunStarted->value, 'payload' => ['payload' => ['messages' => []]]],
        ];
        if ($childTurn) {
            $specs[] = ['type' => RunEventTypeEnum::AgentEnd->value, 'payload' => ['reason' => 'completed']];
        }
        $specs[] = ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => [
            'kind' => 'shell_command',
            'text' => '!printf repair-standalone-shell',
            'idempotency_key' => $key,
            'standalone' => true,
            'current_operation' => AttributeSerializerValidatorTestFactory::create()[0]->normalize(
                new CurrentOperationDTO($turnNo, $stepId, 1, $key),
            ),
        ]];
        if ($childTurn) {
            $specs[] = ['type' => RunEventTypeEnum::TurnAdvanced->value, 'turn_no' => $turnNo, 'payload' => [
                'turn_no' => $turnNo,
                'step_id' => $stepId,
                'operation_attempt' => 1,
                'operation_idempotency_key' => $key,
            ]];
        }
        $events = $factory->eventsFromSpecs($runId, $turnNo, 1, $specs);
        $this->persistRunEvents($runId, $events);
        $store = new InMemoryRunStore();
        $store->compareAndSwap(new RunState(
            runId: $runId,
            status: $childTurn ? RunStatus::Running : RunStatus::Queued,
            version: 1,
            turnNo: $turnNo,
            lastSeq: \count($events),
            activeStepId: $stepId,
        ), 0);
        $bus = new TestMessageBus();
        $service = $this->createService($store, dispatcherBus: $bus);

        $this->assertSame(0, $service->repair($runId, false)->activeOperationsRedriven);
        $this->assertSame([], $bus->messages);

        $result = $service->repair($runId, true);
        $this->assertSame(1, $result->activeOperationsRedriven);
        $this->assertCount(1, $bus->messages);
        $this->assertInstanceOf(ExecuteShellToolCall::class, $bus->messages[0]);
        $this->assertSame($toolCallId, $bus->messages[0]->toolCallId);
        $this->assertSame($turnNo, $bus->messages[0]->turnNo());
        $this->assertTrue($bus->messages[0]->standalone);
        $this->assertNotInstanceOf(ExecuteLlmStep::class, $bus->messages[0]);
    }

    public function testRepeatedApplyRedrivesDurablePendingAndInFlightToolCalls(): void
    {
        $runId = 'repair-tools';
        $stepId = 'tool-step';
        $pending = new ExecuteToolCall($runId, 3, $stepId, 1, 'tool-pending-key', 'call-pending', 'read', ['path' => 'a.txt'], 0);
        $inFlight = new ExecuteToolCall($runId, 3, $stepId, 1, 'tool-in-flight-key', 'write', 'write', ['path' => 'b.txt', 'content' => 'b'], 1);
        $batchStore = $this->createStub(ToolBatchStoreInterface::class);
        $batchStore->method('load')->willReturn(new ToolBatchStateDTO(
            expectedOrder: ['call-pending' => 0, 'write' => 1],
            calls: ['call-pending' => $pending, 'write' => $inFlight],
            pendingQueue: ['call-pending'],
            inFlight: ['write' => true],
            results: [],
            finalized: false,
            maxParallelism: 2,
        ));
        $this->persistActiveToolBatchEvents($runId, $stepId);
        $store = new InMemoryRunStore();
        $store->compareAndSwap(new RunState(runId: $runId, status: RunStatus::Running, version: 1, turnNo: 3, lastSeq: 3, activeStepId: $stepId), 0);
        $bus = new TestMessageBus();
        $service = $this->createService($store, dispatcherBus: $bus, toolBatchStore: $batchStore);
        $before = $this->readEvents($runId);

        $this->assertSame(0, $service->repair($runId, false)->activeOperationsRedriven);
        $this->assertSame([], $bus->messages);

        $service->repair($runId, true);
        $service->repair($runId, true);
        $this->assertCount(4, $bus->messages);
        $this->assertContainsOnlyInstancesOf(ExecuteToolCall::class, $bus->messages);
        $this->assertSame(['tool-in-flight-key', 'tool-pending-key'], $this->toolKeys($bus->messages, 0, 2));
        $this->assertSame(['tool-in-flight-key', 'tool-pending-key'], $this->toolKeys($bus->messages, 2, 2));
        $this->assertSame($before, $this->readEvents($runId));
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
        $store = new InMemoryRunStore();
        $store->compareAndSwap(new RunState(runId: $runId, status: RunStatus::WaitingHuman, version: 1, turnNo: 3, lastSeq: 4, activeStepId: $stepId), 0);
        $bus = new TestMessageBus();
        $service = $this->createService($store, dispatcherBus: $bus, toolBatchStore: $batchStore);
        $before = $this->readEvents($runId);

        $result = $service->repair($runId, true);
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
        $store = new InMemoryRunStore();
        $store->compareAndSwap(new RunState(runId: $runId, status: RunStatus::Running, version: 1, lastSeq: 1), 0);
        $bus = new TestMessageBus();
        $service = $this->createService($store, dispatcherBus: $bus);
        $before = $this->readEvents($runId);
        $key = hash('sha256', $runId.'|repair-advance|0|1');

        $this->assertSame(0, $service->repair($runId, false)->activeOperationsRedriven);
        $this->assertSame([], $bus->messages);

        $service->repair($runId, true);
        $service->repair($runId, true);
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
        $result = $service->repair($runId, false);

        $this->assertTrue($result->repairableStaleCancellationDetected);
        $this->assertNull($result->replayOk);
        $this->assertStringContainsStringIgnoringCase('stale non-terminal cancellation', $result->message);
    }

    public function testApplyRepairsStaleCancellationAppendsTerminalEventsAndRebuildsCancelled(): void
    {
        $runId = '2';
        $this->seedStaleCancellationHistory($runId, unresolvedTool: false);
        $runStore = $this->createStaleCancellationRunStore($runId, unresolvedTool: false);
        $originalPrefix = $this->readRawLines($runId);

        $service = $this->createService($runStore);
        $result = $service->repair($runId, true);

        $this->assertTrue($result->staleCancellationRepaired);
        $this->assertGreaterThanOrEqual(1, $result->terminalEventsAppended);
        $this->assertTrue($result->replayOk);

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
        $result = $service->repair($runId, true);

        $this->assertGreaterThanOrEqual(1, $result->terminalEventsAppended);

        $decoded = $this->readEvents($runId);
        $last = $decoded[\count($decoded) - 1];
        $this->assertSame(RunEventTypeEnum::AgentEnd->value, $last['type']);
        $this->assertSame('cancelled', $last['payload']['reason'] ?? null);

        $this->assertSame(1, $this->countEvents($decoded, RunEventTypeEnum::ToolCallResultReceived->value, self::TOOL_CALL_ID));
        $this->assertSame(1, $this->countEvents($decoded, RunEventTypeEnum::ToolExecutionEnd->value, self::TOOL_CALL_ID));
        $this->assertSame(1, $this->countEvents($decoded, RunEventTypeEnum::MessageEnd->value, self::TOOL_CALL_ID, role: 'tool'));

        foreach ($decoded as $row) {
            if (RunEventTypeEnum::ToolExecutionEnd->value !== $row['type']) {
                continue;
            }
            if (($row['payload']['tool_call_id'] ?? null) !== self::TOOL_CALL_ID) {
                continue;
            }
            $this->assertTrue($row['payload']['is_error'] ?? false);
            $this->assertNotTrue($row['payload']['success'] ?? null, 'Unresolved tool must not be silently marked successful');
        }

        $replayed = $this->replayMessages($runId);
        $this->assertCount(2, $replayed);
        $this->assertSame('assistant', $replayed[0]->role);
        $this->assertSame('tool', $replayed[1]->role);
        $this->assertSame(self::TOOL_CALL_ID, $replayed[1]->toolCallId);

        $second = $service->repair($runId, true);
        $this->assertFalse($second->repairableStaleCancellationDetected);
        $this->assertSame(1, $this->countEvents($this->readEvents($runId), RunEventTypeEnum::ToolCallResultReceived->value, self::TOOL_CALL_ID));
        $this->assertSame(1, $this->countEvents($this->readEvents($runId), RunEventTypeEnum::ToolExecutionEnd->value, self::TOOL_CALL_ID));
        $this->assertSame(1, $this->countEvents($this->readEvents($runId), RunEventTypeEnum::MessageEnd->value, self::TOOL_CALL_ID, role: 'tool'));

        $this->assertReplayStatus($runId, RunStatus::Cancelled);
    }

    public function testRepairIsIdempotent(): void
    {
        $runId = '2';
        $this->seedStaleCancellationHistory($runId, unresolvedTool: false);
        $runStore = $this->createStaleCancellationRunStore($runId, unresolvedTool: false);

        $service = $this->createService($runStore);
        $service->repair($runId, true);

        $lineCountAfterFirst = \count($this->readRawLines($runId));

        $second = $service->repair($runId, true);
        $this->assertFalse($second->repairableStaleCancellationDetected);
        $this->assertSame(0, $second->terminalEventsAppended);
        $this->assertSame($lineCountAfterFirst, \count($this->readRawLines($runId)));
    }

    public function testRepairTerminalCancelledMalformedBatchAppendsMissingToolMessageOnly(): void
    {
        $runId = 'terminal-malformed';
        $toolA = 'chatcmpl-tool-86ac71396b23bc7c';
        $toolB = 'chatcmpl-tool-bbbbbbbbbbbbbbbb';
        $stepId = 'step-two-tool-cancel';
        $turnNo = 2;

        $factory = new EventFactory();
        $normalizer = new AgentMessageNormalizer();
        $toolBResult = new ToolCallResult(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', $toolB.'-cancel'),
            toolCallId: $toolB,
            orderIndex: 1,
            result: [
                'tool_name' => 'bash',
                'content' => [['type' => 'text', 'text' => 'Tool execution cancelled by user.']],
            ],
            isError: true,
            error: ['type' => 'cancelled', 'message' => 'Tool execution cancelled by user.'],
        );
        $toolBMessage = $normalizer->toolMessage($toolBResult)->toArray();

        $events = $factory->eventsFromSpecs($runId, $turnNo, 1, [
            ['type' => RunEventTypeEnum::AgentStart->value, 'payload' => ['messages' => []]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => $turnNo, 'step_id' => $stepId]],
            ['type' => RunEventTypeEnum::LlmStepCompleted->value, 'payload' => [
                'step_id' => $stepId,
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [
                        [
                            'id' => $toolA,
                            'type' => 'function',
                            'function' => ['name' => 'bash', 'arguments' => '{"command":"printf done"}'],
                            'name' => 'bash',
                            'order_index' => 0,
                        ],
                        [
                            'id' => $toolB,
                            'type' => 'function',
                            'function' => ['name' => 'bash', 'arguments' => '{"command":"sleep 8"}'],
                            'name' => 'bash',
                            'order_index' => 1,
                        ],
                    ],
                ],
            ]],
            // A: durable start/result/end WITHOUT tool message projection (the corrupt shape).
            ['type' => RunEventTypeEnum::ToolExecutionStart->value, 'payload' => [
                'tool_call_id' => $toolA,
                'tool_name' => 'bash',
                'order_index' => 0,
                'mode' => 'parallel',
                'step_id' => $stepId,
            ]],
            ['type' => RunEventTypeEnum::ToolCallResultReceived->value, 'payload' => [
                'tool_call_id' => $toolA,
                'order_index' => 0,
                'is_error' => false,
            ]],
            ['type' => RunEventTypeEnum::ToolExecutionEnd->value, 'payload' => [
                'tool_call_id' => $toolA,
                'order_index' => 0,
                'is_error' => false,
                'result' => 'done',
            ]],
            ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => ['kind' => 'cancel']],
            // B: full cancelled group including tool message.
            ['type' => RunEventTypeEnum::ToolExecutionStart->value, 'payload' => [
                'tool_call_id' => $toolB,
                'tool_name' => 'bash',
                'order_index' => 1,
                'mode' => 'parallel',
                'step_id' => $stepId,
            ]],
            ['type' => RunEventTypeEnum::ToolCallResultReceived->value, 'payload' => [
                'tool_call_id' => $toolB,
                'order_index' => 1,
                'is_error' => true,
            ]],
            ['type' => RunEventTypeEnum::ToolExecutionEnd->value, 'payload' => [
                'tool_call_id' => $toolB,
                'order_index' => 1,
                'is_error' => true,
                'result' => 'Tool execution cancelled by user.',
                'cancelled' => true,
                'cancellation_reason' => 'user',
            ]],
            ['type' => RunEventTypeEnum::MessageStart->value, 'payload' => [
                'message_role' => 'tool',
                'tool_call_id' => $toolB,
            ]],
            ['type' => RunEventTypeEnum::MessageEnd->value, 'payload' => [
                'message_role' => 'tool',
                'tool_call_id' => $toolB,
                'message' => $toolBMessage,
            ]],
            ['type' => RunEventTypeEnum::ToolBatchCommitted->value, 'payload' => [
                'count' => 1,
                'turn_no' => $turnNo,
                'step_id' => $stepId,
            ]],
            ['type' => RunEventTypeEnum::AgentEnd->value, 'payload' => ['reason' => 'cancelled']],
        ]);
        $this->persistRunEvents($runId, $events);
        $originalPrefix = $this->readRawLines($runId);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $runId,
            status: RunStatus::Cancelled,
            version: 1,
            turnNo: $turnNo,
            lastSeq: \count($events),
            pendingToolCalls: [],
            activeStepId: $stepId,
            model: 'test-model'), 0);

        $service = $this->createService($runStore);

        $dryRun = $service->repair($runId, false);
        $this->assertTrue($dryRun->repairableStaleCancellationDetected);
        $this->assertStringContainsStringIgnoringCase('unmatched assistant tool', $dryRun->message);

        $apply = $service->repair($runId, true);
        $this->assertTrue($apply->staleCancellationRepaired);
        $this->assertTrue($apply->replayOk);
        $this->assertSame(2, $apply->terminalEventsAppended, 'Only missing A tool message_start/end');

        $decoded = $this->readEvents($runId);
        $appended = \array_slice($decoded, \count($events));
        $this->assertCount(2, $appended);
        $this->assertSame(RunEventTypeEnum::MessageStart->value, $appended[0]['type']);
        $this->assertSame($toolA, $appended[0]['payload']['tool_call_id'] ?? null);
        $this->assertSame(RunEventTypeEnum::MessageEnd->value, $appended[1]['type']);
        $this->assertSame($toolA, $appended[1]['payload']['tool_call_id'] ?? null);

        $this->assertSame(1, $this->countEvents($decoded, RunEventTypeEnum::ToolCallResultReceived->value, $toolA));
        $this->assertSame(1, $this->countEvents($decoded, RunEventTypeEnum::ToolExecutionEnd->value, $toolA));
        $this->assertSame(1, $this->countEvents($decoded, RunEventTypeEnum::MessageEnd->value, $toolA, role: 'tool'));
        $this->assertSame(1, $this->countEvents($decoded, RunEventTypeEnum::AgentEnd->value));
        $this->assertSame(1, $this->countEvents($decoded, RunEventTypeEnum::ToolBatchCommitted->value));

        for ($i = 0; $i < \count($originalPrefix); ++$i) {
            $this->assertSame($originalPrefix[$i], $this->readRawLines($runId)[$i], \sprintf('Original line %d must be unchanged', $i + 1));
        }

        $replayed = $this->replayMessages($runId);
        $validator = new AgentMessageToolCallSequenceValidator();
        $validator->validate($replayed);
        $validator->validate(array_merge(
            $replayed,
            [new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'follow up']])],
        ));

        $second = $service->repair($runId, true);
        $this->assertFalse($second->repairableStaleCancellationDetected);
        $this->assertStringContainsStringIgnoringCase('no repairable corruption', $second->message);
        $this->assertSame(1, $this->countEvents($this->readEvents($runId), RunEventTypeEnum::AgentEnd->value));
        $this->assertSame(1, $this->countEvents($this->readEvents($runId), RunEventTypeEnum::ToolBatchCommitted->value));
        $this->assertSame(1, $this->countEvents($this->readEvents($runId), RunEventTypeEnum::MessageEnd->value, $toolA, role: 'tool'));
    }

    public function testRepairTerminalCancelledUnclosedBatchAfterUserFollowUpIsNotSilentlyClean(): void
    {
        $runId = 'terminal-unclosed-user';
        $toolA = 'chatcmpl-tool-aaaaaaaaaaaaaaaa';
        $toolB = 'chatcmpl-tool-bbbbbbbbbbbbbbbb';
        $stepId = 'step-unclosed-user';
        $turnNo = 2;

        $factory = new EventFactory();
        $normalizer = new AgentMessageNormalizer();
        $toolBResult = new ToolCallResult(
            runId: $runId,
            turnNo: $turnNo,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', $toolB.'-cancel'),
            toolCallId: $toolB,
            orderIndex: 1,
            result: [
                'tool_name' => 'bash',
                'content' => [['type' => 'text', 'text' => 'Tool execution cancelled by user.']],
            ],
            isError: true,
            error: ['type' => 'cancelled', 'message' => 'Tool execution cancelled by user.'],
        );
        $toolBMessage = $normalizer->toolMessage($toolBResult)->toArray();

        $events = $factory->eventsFromSpecs($runId, $turnNo, 1, [
            ['type' => RunEventTypeEnum::AgentStart->value, 'payload' => ['messages' => []]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => $turnNo, 'step_id' => $stepId]],
            ['type' => RunEventTypeEnum::LlmStepCompleted->value, 'payload' => [
                'step_id' => $stepId,
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [
                        [
                            'id' => $toolA,
                            'type' => 'function',
                            'function' => ['name' => 'bash', 'arguments' => '{"command":"printf done"}'],
                            'name' => 'bash',
                            'order_index' => 0,
                        ],
                        [
                            'id' => $toolB,
                            'type' => 'function',
                            'function' => ['name' => 'bash', 'arguments' => '{"command":"sleep 8"}'],
                            'name' => 'bash',
                            'order_index' => 1,
                        ],
                    ],
                ],
            ]],
            ['type' => RunEventTypeEnum::ToolExecutionStart->value, 'payload' => [
                'tool_call_id' => $toolA,
                'tool_name' => 'bash',
                'order_index' => 0,
                'mode' => 'parallel',
                'step_id' => $stepId,
            ]],
            ['type' => RunEventTypeEnum::ToolCallResultReceived->value, 'payload' => [
                'tool_call_id' => $toolA,
                'order_index' => 0,
                'is_error' => false,
            ]],
            ['type' => RunEventTypeEnum::ToolExecutionEnd->value, 'payload' => [
                'tool_call_id' => $toolA,
                'order_index' => 0,
                'is_error' => false,
                'result' => 'done',
            ]],
            ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => ['kind' => 'cancel']],
            ['type' => RunEventTypeEnum::ToolExecutionStart->value, 'payload' => [
                'tool_call_id' => $toolB,
                'tool_name' => 'bash',
                'order_index' => 1,
                'mode' => 'parallel',
                'step_id' => $stepId,
            ]],
            ['type' => RunEventTypeEnum::ToolCallResultReceived->value, 'payload' => [
                'tool_call_id' => $toolB,
                'order_index' => 1,
                'is_error' => true,
            ]],
            ['type' => RunEventTypeEnum::ToolExecutionEnd->value, 'payload' => [
                'tool_call_id' => $toolB,
                'order_index' => 1,
                'is_error' => true,
                'result' => 'Tool execution cancelled by user.',
                'cancelled' => true,
                'cancellation_reason' => 'user',
            ]],
            ['type' => RunEventTypeEnum::MessageStart->value, 'payload' => [
                'message_role' => 'tool',
                'tool_call_id' => $toolB,
            ]],
            ['type' => RunEventTypeEnum::MessageEnd->value, 'payload' => [
                'message_role' => 'tool',
                'tool_call_id' => $toolB,
                'message' => $toolBMessage,
            ]],
            // Failed follow-up durably appended a user message while the batch was still open.
            ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => [
                'kind' => 'follow_up',
                'message' => [
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'please continue']],
                ],
            ]],
            ['type' => RunEventTypeEnum::ToolBatchCommitted->value, 'payload' => [
                'count' => 1,
                'turn_no' => $turnNo,
                'step_id' => $stepId,
            ]],
            ['type' => RunEventTypeEnum::AgentEnd->value, 'payload' => ['reason' => 'cancelled']],
        ]);
        $this->persistRunEvents($runId, $events);
        $originalPrefix = $this->readRawLines($runId);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $runId,
            status: RunStatus::Cancelled,
            version: 1,
            turnNo: $turnNo,
            lastSeq: \count($events),
            pendingToolCalls: [],
            activeStepId: $stepId,
            model: 'test-model'), 0);

        $service = $this->createService($runStore);

        $dryRun = $service->repair($runId, false);
        $this->assertFalse($dryRun->repairableStaleCancellationDetected);
        $this->assertStringContainsStringIgnoringCase('cannot reorder', $dryRun->message);

        $apply = $service->repair($runId, true);
        $this->assertFalse($apply->staleCancellationRepaired);
        $this->assertSame(0, $apply->terminalEventsAppended);
        $this->assertStringContainsStringIgnoringCase('cannot reorder', $apply->message);

        for ($i = 0; $i < \count($originalPrefix); ++$i) {
            $this->assertSame($originalPrefix[$i], $this->readRawLines($runId)[$i]);
        }

        $this->expectException(\Ineersa\AgentCore\Infrastructure\SymfonyAi\MalformedToolCallSequenceException::class);
        (new AgentMessageToolCallSequenceValidator())->validate($this->replayMessages($runId));
    }

    public function testRepairNeverEditsOrReordersExistingEvents(): void
    {
        $runId = '2';
        $this->seedStaleCancellationHistory($runId, unresolvedTool: false);
        $original = $this->readRawLines($runId);

        $runStore = $this->createStaleCancellationRunStore($runId, unresolvedTool: false);

        $service = $this->createService($runStore);
        $service->repair($runId, true);

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
            $factory->event($runId, 1, 0, RunEventTypeEnum::AgentStart->value, []),
            $factory->event($runId, 2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $factory->event($runId, 3, 1, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
            $factory->event($runId, 3, 1, RunEventTypeEnum::AgentCommandApplied->value, ['kind' => 'cancel']),
        ]);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(RunState::queued($runId), 0);

        $service = $this->createService($runStore);
        $before = $this->readRawLines($runId);
        $result = $service->repair($runId, true);

        $this->assertFalse($result->repairableStaleCancellationDetected);
        $this->assertNotEmpty($result->duplicateSeqs);
        $this->assertSame(SessionRepairRefusalReasonEnum::DuplicateSequences, $result->refusalReason);
        $this->assertStringContainsStringIgnoringCase('duplicate', $result->message);
        $this->assertSame($before, $this->readRawLines($runId));
    }

    public function testActiveStreamingProducesRefusal(): void
    {
        $runId = 'stream';
        $factory = new EventFactory();
        $this->persistRunEvents($runId, $factory->eventsFromSpecs($runId, 1, 1, [
            ['type' => RunEventTypeEnum::AgentStart->value, 'payload' => []],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => 1, 'step_id' => 'llm-1']],
            ['type' => RunEventTypeEnum::MessageStart->value, 'payload' => ['message_id' => 'm1', 'message_role' => 'assistant']],
            ['type' => RunEventTypeEnum::MessageUpdate->value, 'payload' => ['message_id' => 'm1', 'delta' => 'partial']],
        ]));

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $runId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 4,
            isStreaming: true,
            streamingMessage: ['message_id' => 'm1'],
            activeStepId: 'llm-1',
            model: 'test-model'), 0);

        $service = $this->createService($runStore);
        $before = $this->readRawLines($runId);
        $result = $service->repair($runId, true);

        $this->assertSame(SessionRepairRefusalReasonEnum::ActiveStreaming, $result->refusalReason);
        $this->assertStringContainsStringIgnoringCase('active streaming', $result->message);
        $this->assertSame($before, $this->readRawLines($runId));
    }

    public function testMissingSequencesProducesTypedRefusal(): void
    {
        $runId = 'missing';
        $factory = new EventFactory();
        $this->persistRunEvents($runId, [
            $factory->event($runId, 1, 0, RunEventTypeEnum::AgentStart->value, []),
            $factory->event($runId, 2, 1, RunEventTypeEnum::TurnAdvanced->value, ['turn_no' => 1]),
            $factory->event($runId, 4, 1, RunEventTypeEnum::AgentEnd->value, ['reason' => 'completed']),
        ]);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(RunState::queued($runId), 0);

        $service = $this->createService($runStore);
        $before = $this->readRawLines($runId);
        $result = $service->repair($runId, true);

        $this->assertSame(SessionRepairRefusalReasonEnum::MissingSequences, $result->refusalReason);
        $this->assertNotEmpty($result->missingSeqs);
        $this->assertSame($before, $this->readRawLines($runId));
    }

    public function testAmbiguousPendingWorkProducesTypedRefusal(): void
    {
        $runId = 'ambiguous';
        $events = $this->buildCanonicalToolTurnPrefix($runId, includeToolStart: true, includeCompletedToolGroup: false);
        $this->persistRunEvents($runId, $events);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $runId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 33,
            lastSeq: \count($events),
            pendingToolCalls: [self::TOOL_CALL_ID => false],
            activeStepId: self::STEP_ID,
            model: 'test-model'), 0);

        $service = $this->createService($runStore);
        $before = $this->readRawLines($runId);
        $result = $service->repair($runId, true);

        $this->assertSame(SessionRepairRefusalReasonEnum::AmbiguousPendingWork, $result->refusalReason);
        $this->assertSame($before, $this->readRawLines($runId));
    }

    public function testDurableTrackedToolResultAcceptedExactlyOnce(): void
    {
        $runId = '2';
        $this->seedStaleCancellationHistory($runId, unresolvedTool: false);
        $runStore = $this->createStaleCancellationRunStore($runId, unresolvedTool: false);

        $beforeRepair = $this->readEvents($runId);
        $this->assertSame(1, $this->countEvents($beforeRepair, RunEventTypeEnum::ToolCallResultReceived->value, self::TOOL_CALL_ID));
        $this->assertSame(1, $this->countEvents($beforeRepair, RunEventTypeEnum::ToolExecutionEnd->value, self::TOOL_CALL_ID));
        $this->assertSame(1, $this->countEvents($beforeRepair, RunEventTypeEnum::MessageStart->value, self::TOOL_CALL_ID, role: 'tool'));
        $this->assertSame(1, $this->countEvents($beforeRepair, RunEventTypeEnum::MessageEnd->value, self::TOOL_CALL_ID, role: 'tool'));
        $this->assertSame(1, $this->countEvents($beforeRepair, RunEventTypeEnum::ToolBatchCommitted->value));

        $service = $this->createService($runStore);
        $first = $service->repair($runId, true);
        $this->assertTrue($first->staleCancellationRepaired);

        $afterFirst = $this->readEvents($runId);
        $this->assertSame(1, $this->countEvents($afterFirst, RunEventTypeEnum::ToolCallResultReceived->value, self::TOOL_CALL_ID));
        $this->assertSame(1, $this->countEvents($afterFirst, RunEventTypeEnum::ToolExecutionEnd->value, self::TOOL_CALL_ID));
        $this->assertSame(1, $this->countEvents($afterFirst, RunEventTypeEnum::MessageStart->value, self::TOOL_CALL_ID, role: 'tool'));
        $this->assertSame(1, $this->countEvents($afterFirst, RunEventTypeEnum::MessageEnd->value, self::TOOL_CALL_ID, role: 'tool'));
        $this->assertSame(1, $this->countEvents($afterFirst, RunEventTypeEnum::ToolBatchCommitted->value));

        $second = $service->repair($runId, true);
        $this->assertFalse($second->repairableStaleCancellationDetected);
        $this->assertSame(0, $second->terminalEventsAppended);

        $afterSecond = $this->readEvents($runId);
        $this->assertSame(1, $this->countEvents($afterSecond, RunEventTypeEnum::ToolCallResultReceived->value, self::TOOL_CALL_ID));
        $this->assertSame(1, $this->countEvents($afterSecond, RunEventTypeEnum::ToolExecutionEnd->value, self::TOOL_CALL_ID));
    }

    public function testIncompleteLlmPhaseReceivesLlmStepAbortedOnCancellationRepair(): void
    {
        $runId = 'llm-incomplete';
        $factory = new EventFactory();
        $turnNo = 33;
        $events = $factory->eventsFromSpecs($runId, $turnNo, 1, [
            ['type' => RunEventTypeEnum::AgentStart->value, 'payload' => ['messages' => []]],
            ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => ['kind' => 'follow_up', 'payload' => ['text' => 'continue']]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => $turnNo, 'step_id' => self::STEP_ID]],
            ['type' => RunEventTypeEnum::MessageStart->value, 'payload' => ['message_id' => 'asst-1', 'message_role' => 'assistant']],
            ['type' => RunEventTypeEnum::MessageUpdate->value, 'payload' => ['message_id' => 'asst-1', 'delta' => 'partial']],
            ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => ['kind' => 'cancel']],
            ['type' => RunEventTypeEnum::AgentCommandRejected->value, 'payload' => ['reason' => 'Command "follow_up" rejected because cancellation is in progress.']],
        ]);
        $this->persistRunEvents($runId, $events);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $runId,
            status: RunStatus::Cancelling,
            version: 1,
            turnNo: $turnNo,
            lastSeq: \count($events),
            activeStepId: self::STEP_ID,
            model: 'test-model'), 0);

        $service = $this->createService($runStore);
        $prefix = \count($events);
        $result = $service->repair($runId, true);
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
        $result = $service->repair($runId, true);
        $this->assertTrue($result->staleCancellationRepaired);

        $decoded = $this->readEvents($runId);
        $appended = \array_slice($decoded, $prefix);
        $this->assertSame(0, $this->countInSlice($appended, RunEventTypeEnum::LlmStepAborted->value));
        $this->assertGreaterThanOrEqual(1, $this->countInSlice($appended, RunEventTypeEnum::ToolExecutionEnd->value, self::TOOL_CALL_ID));
    }

    public function testNoEventsRefusalLogsStructuredRefusal(): void
    {
        $runId = 'no-events';
        $logger = new TestLogger();
        $service = $this->createService(logger: $logger);
        $result = $service->repair($runId, true);

        $this->assertSame(SessionRepairRefusalReasonEnum::NoEvents, $result->refusalReason);
        $this->assertCount(1, $logger->records);
        $this->assertSame('session_repair.refused', $logger->records[0]['message']);
        $this->assertSame('no_events', $logger->records[0]['context']['refusal_reason']);
        $this->assertSame($runId, $logger->records[0]['context']['run_id']);
    }

    public function testRunStateUnavailableRefusalLogsStructuredRefusal(): void
    {
        $runId = 'missing-state';
        $factory = new EventFactory();
        $this->persistRunEvents($runId, $factory->eventsFromSpecs($runId, 1, 1, [
            ['type' => RunEventTypeEnum::AgentStart->value, 'payload' => []],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => 1, 'step_id' => 's1']],
        ]));

        $logger = new TestLogger();
        $service = $this->createService(runStore: new InMemoryRunStore(), logger: $logger);
        $result = $service->repair($runId, true);

        $this->assertSame(SessionRepairRefusalReasonEnum::RunStateUnavailable, $result->refusalReason);
        $this->assertCount(1, $logger->records);
        $this->assertSame('session_repair.refused', $logger->records[0]['message']);
        $this->assertSame('run_state_unavailable', $logger->records[0]['context']['refusal_reason']);
    }

    public function testMultiTurnLlmAbortTargetsOnlyLatestIncompletePhase(): void
    {
        $runId = 'multi-turn-llm';
        $factory = new EventFactory();
        $firstStep = 'follow_up-first';
        $secondStep = 'follow_up-second';
        $events = $factory->eventsFromSpecs($runId, 33, 1, [
            ['type' => RunEventTypeEnum::AgentStart->value, 'payload' => ['messages' => []]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => 1, 'step_id' => $firstStep]],
            ['type' => RunEventTypeEnum::MessageStart->value, 'payload' => ['message_id' => 'asst-old', 'message_role' => 'assistant']],
            ['type' => RunEventTypeEnum::MessageEnd->value, 'payload' => ['message_id' => 'asst-old', 'message_role' => 'assistant', 'message' => ['role' => 'assistant', 'content' => 'done']]],
            ['type' => RunEventTypeEnum::LlmStepCompleted->value, 'payload' => ['step_id' => $firstStep, 'assistant_message' => ['role' => 'assistant', 'content' => 'done']]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => 33, 'step_id' => $secondStep]],
            ['type' => RunEventTypeEnum::MessageStart->value, 'payload' => ['message_id' => 'asst-new', 'message_role' => 'assistant']],
            ['type' => RunEventTypeEnum::MessageUpdate->value, 'payload' => ['message_id' => 'asst-new', 'delta' => 'partial']],
            ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => ['kind' => 'cancel']],
        ]);
        $this->persistRunEvents($runId, $events);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $runId,
            status: RunStatus::Cancelling,
            version: 1,
            turnNo: 33,
            lastSeq: \count($events),
            activeStepId: $secondStep,
            model: 'test-model'), 0);

        $service = $this->createService($runStore);
        $prefix = \count($events);
        $service->repair($runId, true);
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

    public function testToolOrderIndexComesFromExecutionStartNotGlobalCounter(): void
    {
        $runId = 'order-index';
        $firstTool = 'call_first';
        $secondTool = 'call_second';
        $factory = new EventFactory();
        $events = $factory->eventsFromSpecs($runId, 33, 1, [
            ['type' => RunEventTypeEnum::AgentStart->value, 'payload' => ['messages' => []]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => 1, 'step_id' => 's1']],
            ['type' => RunEventTypeEnum::LlmStepCompleted->value, 'payload' => [
                'step_id' => 's1',
                'assistant_message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => $firstTool,
                    'type' => 'function',
                    'function' => ['name' => 'read', 'arguments' => '{}'],
                ]]],
            ]],
            ['type' => RunEventTypeEnum::ToolExecutionStart->value, 'payload' => [
                'tool_call_id' => $firstTool,
                'tool_name' => 'read',
                'order_index' => 7,
                'mode' => 'async',
                'step_id' => 's1',
            ]],
            ['type' => RunEventTypeEnum::ToolExecutionEnd->value, 'payload' => [
                'tool_call_id' => $firstTool,
                'order_index' => 7,
                'is_error' => false,
                'result' => 'ok',
            ]],
            ['type' => RunEventTypeEnum::TurnAdvanced->value, 'payload' => ['turn_no' => 33, 'step_id' => self::STEP_ID]],
            ['type' => RunEventTypeEnum::LlmStepCompleted->value, 'payload' => [
                'step_id' => self::STEP_ID,
                'assistant_message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [[
                    'id' => $secondTool,
                    'type' => 'function',
                    'function' => ['name' => 'subagent', 'arguments' => '{}'],
                ]]],
            ]],
            ['type' => RunEventTypeEnum::ToolExecutionStart->value, 'payload' => [
                'tool_call_id' => $secondTool,
                'tool_name' => 'subagent',
                'order_index' => 2,
                'mode' => 'async',
                'step_id' => self::STEP_ID,
            ]],
            ['type' => RunEventTypeEnum::AgentCommandApplied->value, 'payload' => ['kind' => 'cancel']],
        ]);
        $this->persistRunEvents($runId, $events);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $runId,
            status: RunStatus::Cancelling,
            version: 1,
            turnNo: 33,
            lastSeq: \count($events),
            pendingToolCalls: [$secondTool => false],
            activeStepId: self::STEP_ID,
            model: 'test-model'), 0);

        $service = $this->createService($runStore);
        $service->repair($runId, true);
        $decoded = $this->readEvents($runId);
        foreach ($decoded as $row) {
            if (RunEventTypeEnum::ToolExecutionEnd->value !== $row['type']) {
                continue;
            }
            if (($row['payload']['tool_call_id'] ?? null) !== $secondTool) {
                continue;
            }
            $this->assertSame(2, $row['payload']['order_index'] ?? null);
        }
    }

    /**
     * @param list<RunEvent> $events
     */
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
            ['type' => RunEventTypeEnum::AgentStart->value, 'payload' => ['messages' => []]],
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
            ['type' => RunEventTypeEnum::AgentStart->value, 'payload' => ['messages' => []]],
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
        $toolMessage = $normalizer->toolMessage($toolResult)->toArray();

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
                'type' => RunEventTypeEnum::ToolCallResultReceived->value,
                'payload' => [
                    'tool_call_id' => $toolCallId,
                    'order_index' => $orderIndex,
                    'is_error' => $isError,
                ],
            ],
            [
                'type' => RunEventTypeEnum::ToolExecutionEnd->value,
                'payload' => [
                    'tool_call_id' => $toolCallId,
                    'order_index' => $orderIndex,
                    'is_error' => $isError,
                    'result' => $resultText,
                ],
            ],
            [
                'type' => RunEventTypeEnum::MessageStart->value,
                'payload' => [
                    'message_role' => 'tool',
                    'tool_call_id' => $toolCallId,
                ],
            ],
            [
                'type' => RunEventTypeEnum::MessageEnd->value,
                'payload' => [
                    'message_role' => 'tool',
                    'tool_call_id' => $toolCallId,
                    'message' => $toolMessage,
                ],
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

    /**
     * @param list<object> $messages
     *
     * @return list<string>
     */
    private function toolKeys(array $messages, int $offset, int $length): array
    {
        $keys = [];
        foreach (\array_slice($messages, $offset, $length) as $message) {
            $this->assertInstanceOf(ExecuteToolCall::class, $message);
            $keys[] = $message->idempotencyKey();
        }
        sort($keys);

        return $keys;
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

    private function createService(?InMemoryRunStore $runStore = null, ?TestLogger $logger = null, ?TestMessageBus $dispatcherBus = null, ?ToolBatchStoreInterface $toolBatchStore = null): SessionRepairService
    {
        $runStore ??= new InMemoryRunStore();
        $dispatcherBus ??= new TestMessageBus();
        $toolBatchStore ??= $this->createStub(ToolBatchStoreInterface::class);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: $this->projectDir,
        );

        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
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
            runStore: $runStore,
            runStateReducer: new RunStateReducer(AttributeSerializerValidatorTestFactory::denormalizer()),
            replayEventPreparer: new ReplayEventPreparer(),
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            toolCallSequenceValidator: new AgentMessageToolCallSequenceValidator(),
            lockManager: new RunLockManager(new LockFactory(new FlockStore($lockDir))),
            logger: $logger ?? new NullLogger(),
            stepDispatcher: new StepDispatcher($dispatcherBus),
            toolBatchStore: $toolBatchStore,
            serializer: AttributeSerializerValidatorTestFactory::create()[0],
        );
    }

    private function createStaleCancellationRunStore(string $runId, bool $unresolvedTool): InMemoryRunStore
    {
        $runStore = new InMemoryRunStore();
        $eventCount = \count($this->buildStaleCancellationEvents($runId, $unresolvedTool));
        $runStore->compareAndSwap(new RunState(
            runId: $runId,
            status: RunStatus::Cancelling,
            version: 1,
            turnNo: 33,
            lastSeq: $eventCount,
            pendingToolCalls: $unresolvedTool ? [self::TOOL_CALL_ID => false] : [self::TOOL_CALL_ID => true],
            activeStepId: self::STEP_ID,
            model: 'test-model'), 0);

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
        $replayed = (new RunStateReducer(AttributeSerializerValidatorTestFactory::denormalizer()))->replay(RunState::queued($runId), $events);

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
        $replayed = (new RunStateReducer(AttributeSerializerValidatorTestFactory::denormalizer()))->replay(RunState::queued($runId), $events);
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
