<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Repair;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\Repair\SessionRepairRefusalReasonEnum;
use Ineersa\CodingAgent\Session\Repair\SessionRepairService;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

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
        ), 0);

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

    public function testDryRunReportsStaleCancellation(): void
    {
        $runId = '2';
        $this->seedStaleCancellationHistory($runId, unresolvedTool: false);
        $runStore = $this->createStaleCancellationRunStore($runId, unresolvedTool: false);

        $service = $this->createService($runStore);
        $result = $service->repair($runId, false);

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
        ), 0);

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
        ), 0);

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
        ), 0);

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
        ), 0);

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
        ), 0);

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

    private function createService(?InMemoryRunStore $runStore = null, ?TestLogger $logger = null): SessionRepairService
    {
        $runStore ??= new InMemoryRunStore();

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
            logger: $logger ?? new NullLogger(),
        );

        return new SessionRepairService(
            eventStore: $eventStore,
            runStore: $runStore,
            runStateReducer: new RunStateReducer(),
            replayEventPreparer: new ReplayEventPreparer(),
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            lockManager: new RunLockManager(new LockFactory(new FlockStore($lockDir))),
            logger: $logger ?? new NullLogger(),
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
        ), 0);

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
     * @return list<\Ineersa\AgentCore\Domain\Message\AgentMessage>
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
            logger: $logger ?? new NullLogger(),
        );

        $events = $eventStore->allFor($runId);
        $replayed = (new RunStateReducer())->replay(RunState::queued($runId), $events);

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
            logger: $logger ?? new NullLogger(),
        );

        $events = $eventStore->allFor($runId);
        $replayed = (new RunStateReducer())->replay(RunState::queued($runId), $events);
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
