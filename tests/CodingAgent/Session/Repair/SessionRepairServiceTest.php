<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Repair;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
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
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class SessionRepairServiceTest extends TestCase
{
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
        $this->writeEvents($runId, [
            '{"schema_version":"1.0","run_id":"1","seq":1,"turn_no":0,"type":"agent_start","payload":{"messages":[]},"ts":"2026-07-09T01:00:00+00:00"}',
            '{"schema_version":"1.0","run_id":"1","seq":2,"turn_no":1,"type":"turn_advanced","payload":{"turn_no":1,"step_id":"follow_up-1"},"ts":"2026-07-09T01:00:01+00:00"}',
            '{"schema_version":"1.0","run_id":"1","seq":3,"turn_no":1,"type":"agent_end","payload":{"reason":"completed"},"ts":"2026-07-09T01:00:02+00:00"}',
        ]);

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
        $this->assertFalse($dryRun->needsRepair);
        $this->assertFalse($dryRun->staleCancellationRepaired);
        $this->assertStringContainsStringIgnoringCase('no repairable corruption', $dryRun->message);

        $apply = $service->repair($runId, true);
        $this->assertFalse($apply->needsRepair);
        $this->assertFalse($apply->staleCancellationRepaired);
        $this->assertSame($before, $this->readEvents($runId));
    }

    public function testDryRunReportsStaleCancellation(): void
    {
        $runId = '2';
        $this->writeStaleCancellationEvents($runId);
        $runStore = $this->createStaleCancellationRunStore($runId);

        $service = $this->createService($runStore);
        $result = $service->repair($runId, false);

        $this->assertStringContainsStringIgnoringCase('stale non-terminal cancellation', $result->message);
    }

    public function testApplyRepairsStaleCancellationAppendsTerminalEventsAndRebuildsCancelled(): void
    {
        $runId = '2';
        $this->writeStaleCancellationEvents($runId);
        $runStore = $this->createStaleCancellationRunStore($runId);

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

        $original = $this->staleCancellationJsonLines($runId);
        for ($i = 0; $i < 7; ++$i) {
            $this->assertSame($original[$i], $lines[$i], \sprintf('Original line %d must be unchanged', $i + 1));
        }
    }

    public function testApplyRepairsStaleCancellationWithUnresolvedToolNeverCompleted(): void
    {
        $runId = '2';
        $this->writeStaleCancellationEvents($runId, unresolvedTool: true);
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $runId,
            status: RunStatus::Cancelling,
            version: 1,
            turnNo: 33,
            lastSeq: 7,
            pendingToolCalls: ['call_00_abc' => false],
            activeStepId: 'follow_up-xyz',
        ), 0);

        $service = $this->createService($runStore);
        $result = $service->repair($runId, true);

        $this->assertGreaterThanOrEqual(1, $result->terminalEventsAppended);

        $decoded = $this->readEvents($runId);
        $last = $decoded[\count($decoded) - 1];
        $this->assertSame(RunEventTypeEnum::AgentEnd->value, $last['type']);
        $this->assertSame('cancelled', $last['payload']['reason'] ?? null);

        $toolEndFound = false;
        foreach ($decoded as $row) {
            if (RunEventTypeEnum::ToolExecutionEnd->value !== $row['type']) {
                continue;
            }
            if (($row['payload']['tool_call_id'] ?? null) !== 'call_00_abc') {
                continue;
            }
            $toolEndFound = true;
            $success = $row['payload']['success'] ?? null;
            $this->assertNotTrue($success, 'Unresolved tool must not be silently marked successful');
        }
        $this->assertTrue($toolEndFound, 'Repair must append an explicit tool_execution_end for the unresolved tool');

        $this->assertReplayStatus($runId, RunStatus::Cancelled);
    }

    public function testRepairIsIdempotent(): void
    {
        $runId = '2';
        $this->writeStaleCancellationEvents($runId);
        $runStore = $this->createStaleCancellationRunStore($runId);

        $service = $this->createService($runStore);
        $first = $service->repair($runId, true);

        $lineCountAfterFirst = \count($this->readRawLines($runId));

        $second = $service->repair($runId, true);
        $this->assertFalse($second->needsRepair);
        $this->assertSame(0, $second->terminalEventsAppended);
        $this->assertSame($lineCountAfterFirst, \count($this->readRawLines($runId)));
    }

    public function testRepairNeverEditsOrReordersExistingEvents(): void
    {
        $runId = '2';
        $original = $this->staleCancellationJsonLines($runId);
        $this->writeEvents($runId, $original);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $runId,
            status: RunStatus::Cancelling,
            version: 1,
            turnNo: 33,
            lastSeq: 7,
            activeStepId: 'follow_up-xyz',
        ), 0);

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
        $this->writeEvents($runId, [
            '{"schema_version":"1.0","run_id":"dup","seq":1,"turn_no":0,"type":"agent_start","payload":{},"ts":"2026-07-09T01:00:00+00:00"}',
            '{"schema_version":"1.0","run_id":"dup","seq":2,"turn_no":1,"type":"turn_advanced","payload":{"turn_no":1},"ts":"2026-07-09T01:00:01+00:00"}',
            '{"schema_version":"1.0","run_id":"dup","seq":3,"turn_no":1,"type":"agent_end","payload":{"reason":"completed"},"ts":"2026-07-09T01:00:02+00:00"}',
            '{"schema_version":"1.0","run_id":"dup","seq":3,"turn_no":1,"type":"agent_command_applied","payload":{"kind":"cancel"},"ts":"2026-07-09T01:00:03+00:00"}',
        ]);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(RunState::queued($runId), 0);

        $service = $this->createService($runStore);
        $before = $this->readRawLines($runId);
        $result = $service->repair($runId, true);

        $this->assertTrue($result->needsRepair);
        $this->assertNotEmpty($result->duplicateSeqs);
        $this->assertSame(SessionRepairRefusalReasonEnum::DuplicateSequences, $result->refusalReason);
        $this->assertStringContainsStringIgnoringCase('duplicate', $result->message);
        $this->assertSame($before, $this->readRawLines($runId));
    }

    public function testActiveStreamingProducesRefusal(): void
    {
        $runId = 'stream';
        $this->writeEvents($runId, [
            '{"schema_version":"1.0","run_id":"stream","seq":1,"turn_no":0,"type":"agent_start","payload":{},"ts":"2026-07-09T01:00:00+00:00"}',
            '{"schema_version":"1.0","run_id":"stream","seq":2,"turn_no":1,"type":"turn_advanced","payload":{"turn_no":1,"step_id":"llm-1"},"ts":"2026-07-09T01:00:01+00:00"}',
            '{"schema_version":"1.0","run_id":"stream","seq":3,"turn_no":1,"type":"message_start","payload":{"message_id":"m1"},"ts":"2026-07-09T01:00:02+00:00"}',
            '{"schema_version":"1.0","run_id":"stream","seq":4,"turn_no":1,"type":"message_update","payload":{"message_id":"m1","delta":"partial"},"ts":"2026-07-09T01:00:03+00:00"}',
        ]);

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
        $this->writeEvents($runId, [
            '{"schema_version":"1.0","run_id":"missing","seq":1,"turn_no":0,"type":"agent_start","payload":{},"ts":"2026-07-09T01:00:00+00:00"}',
            '{"schema_version":"1.0","run_id":"missing","seq":2,"turn_no":1,"type":"turn_advanced","payload":{"turn_no":1},"ts":"2026-07-09T01:00:01+00:00"}',
            '{"schema_version":"1.0","run_id":"missing","seq":4,"turn_no":1,"type":"agent_end","payload":{"reason":"completed"},"ts":"2026-07-09T01:00:02+00:00"}',
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
        $this->writeEvents($runId, [
            '{"schema_version":"1.0","run_id":"ambiguous","seq":1,"turn_no":0,"type":"agent_start","payload":{},"ts":"2026-07-09T01:00:00+00:00"}',
            '{"schema_version":"1.0","run_id":"ambiguous","seq":2,"turn_no":1,"type":"turn_advanced","payload":{"turn_no":1,"step_id":"llm-1"},"ts":"2026-07-09T01:00:01+00:00"}',
            '{"schema_version":"1.0","run_id":"ambiguous","seq":3,"turn_no":1,"type":"llm_step_completed","payload":{"assistant_message":{"role":"assistant","content":null,"tool_calls":[{"id":"call_amb","type":"function","function":{"name":"read","arguments":"{}"}}]}},"ts":"2026-07-09T01:00:02+00:00"}',
            '{"schema_version":"1.0","run_id":"ambiguous","seq":4,"turn_no":1,"type":"tool_execution_start","payload":{"tool_call_id":"call_amb","tool_name":"read"},"ts":"2026-07-09T01:00:03+00:00"}',
        ]);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $runId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 4,
            pendingToolCalls: ['call_amb' => false],
            activeStepId: 'llm-1',
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
        $this->writeStaleCancellationEvents($runId);
        $runStore = $this->createStaleCancellationRunStore($runId);

        $service = $this->createService($runStore);
        $first = $service->repair($runId, true);
        $this->assertTrue($first->staleCancellationRepaired);

        $toolEndCountAfterFirst = 0;
        foreach ($this->readEvents($runId) as $row) {
            if (RunEventTypeEnum::ToolExecutionEnd->value === $row['type']
                && ($row['payload']['tool_call_id'] ?? null) === 'call_00_abc') {
                ++$toolEndCountAfterFirst;
            }
        }
        $this->assertSame(1, $toolEndCountAfterFirst, 'Canonical stream must contain exactly one durable tool_execution_end');

        $second = $service->repair($runId, true);
        $this->assertFalse($second->needsRepair);
        $this->assertSame(0, $second->terminalEventsAppended);

        $toolEndCountAfterSecond = 0;
        foreach ($this->readEvents($runId) as $row) {
            if (RunEventTypeEnum::ToolExecutionEnd->value === $row['type']
                && ($row['payload']['tool_call_id'] ?? null) === 'call_00_abc') {
                ++$toolEndCountAfterSecond;
            }
        }
        $this->assertSame(1, $toolEndCountAfterSecond);
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

    private function createService(?InMemoryRunStore $runStore = null): SessionRepairService
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
            logger: new NullLogger(),
        );

        return new SessionRepairService(
            eventStore: $eventStore,
            runStore: $runStore,
            runStateReducer: new RunStateReducer(),
            replayEventPreparer: new ReplayEventPreparer(),
            eventFactory: new EventFactory(),
            lockManager: new RunLockManager(new LockFactory(new FlockStore($lockDir))),
            logger: new NullLogger(),
        );
    }

    private function createStaleCancellationRunStore(string $runId): InMemoryRunStore
    {
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $runId,
            status: RunStatus::Cancelling,
            version: 1,
            turnNo: 33,
            lastSeq: 7,
            pendingToolCalls: ['call_00_abc' => true],
            activeStepId: 'follow_up-xyz',
        ), 0);

        return $runStore;
    }

    private function writeStaleCancellationEvents(string $runId, bool $unresolvedTool = false): void
    {
        $this->writeEvents($runId, $this->staleCancellationJsonLines($runId, $unresolvedTool));
    }

    /**
     * @return list<string>
     */
    private function staleCancellationJsonLines(string $runId, bool $unresolvedTool = false): array
    {
        $toolLine = $unresolvedTool
            ? '{"schema_version":"1.0","run_id":"'.$runId.'","seq":5,"turn_no":33,"type":"tool_execution_start","payload":{"tool_call_id":"call_00_abc","tool_name":"subagent"},"ts":"2026-07-09T01:00:04+00:00"}'
            : '{"schema_version":"1.0","run_id":"'.$runId.'","seq":5,"turn_no":33,"type":"tool_execution_end","payload":{"tool_call_id":"call_00_abc","tool_name":"subagent","success":true},"ts":"2026-07-09T01:00:04+00:00"}';

        return [
            '{"schema_version":"1.0","run_id":"'.$runId.'","seq":1,"turn_no":0,"type":"agent_start","payload":{"messages":[]},"ts":"2026-07-09T01:00:00+00:00"}',
            '{"schema_version":"1.0","run_id":"'.$runId.'","seq":2,"turn_no":33,"type":"agent_command_applied","payload":{"kind":"follow_up","payload":{"text":"run subagent"}},"ts":"2026-07-09T01:00:01+00:00"}',
            '{"schema_version":"1.0","run_id":"'.$runId.'","seq":3,"turn_no":33,"type":"turn_advanced","payload":{"turn_no":33,"step_id":"follow_up-abc"},"ts":"2026-07-09T01:00:02+00:00"}',
            '{"schema_version":"1.0","run_id":"'.$runId.'","seq":4,"turn_no":33,"type":"llm_step_completed","payload":{"assistant_message":{"role":"assistant","content":null,"tool_calls":[{"id":"call_00_abc","type":"function","function":{"name":"subagent","arguments":"{}"}}]}},"ts":"2026-07-09T01:00:03+00:00"}',
            $toolLine,
            '{"schema_version":"1.0","run_id":"'.$runId.'","seq":6,"turn_no":33,"type":"agent_command_applied","payload":{"kind":"cancel"},"ts":"2026-07-09T01:00:05+00:00"}',
            '{"schema_version":"1.0","run_id":"'.$runId.'","seq":7,"turn_no":33,"type":"agent_command_rejected","payload":{"reason":"Command \"follow_up\" rejected because cancellation is in progress."},"ts":"2026-07-09T01:00:06+00:00"}',
        ];
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
