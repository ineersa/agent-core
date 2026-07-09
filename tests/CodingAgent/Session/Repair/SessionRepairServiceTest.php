<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Repair;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
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
use Ineersa\CodingAgent\Session\Repair\SessionRepairService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[AllowMockObjectsWithoutExpectations]
final class SessionRepairServiceTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/hatfield-repair-'.getmypid();
        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
        mkdir($this->projectDir.'/.hatfield/sessions/2', 0777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
    }

    public function testDryRunReportsDuplicateSequences(): void
    {
        $this->writeEvents([
            '{"schema_version":"1.0","run_id":"2","seq":345,"turn_no":29,"type":"agent_command_applied","payload":{"kind":"cancel"},"ts":"2026-07-09T01:55:42+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":345,"turn_no":29,"type":"tool_execution_update","payload":{"tool_name":"fork"},"ts":"2026-07-09T01:55:42+00:00"}',
        ]);

        $result = $this->createService()->repair('2', false);
        $this->assertSame([345], $result['duplicateSeqs']);
        $this->assertStringContainsString('Repair preview', $result['message']);
    }

    public function testApplyRenumbersEventsAndRebuildsState(): void
    {
        $this->writeEvents([
            '{"schema_version":"1.0","run_id":"2","seq":345,"turn_no":29,"type":"agent_command_applied","payload":{"kind":"cancel"},"ts":"2026-07-09T01:55:42+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":345,"turn_no":29,"type":"tool_execution_update","payload":{"tool_name":"fork"},"ts":"2026-07-09T01:55:42+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":381,"turn_no":27,"type":"leaf_set","payload":{"turn_no":27},"ts":"2026-07-09T02:56:44+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":381,"turn_no":32,"type":"agent_end","payload":{"reason":"failed","error":"Cannot replay run 2: duplicate sequence number(s): 345, 366."},"ts":"2026-07-09T02:56:56+00:00"}',
        ]);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(runId: '2', status: RunStatus::Failed, version: 1, lastSeq: 381), 0);
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->method('rebuildIfStale')->willReturn(RunStateReplayResult::rebuilt(
            new RunState(runId: '2', status: RunStatus::Cancelled, version: 2, lastSeq: 3),
            3,
            3,
            true,
        ));

        $result = $this->createService($runStore, $rebuilder)->repair('2', true);
        $this->assertTrue($result['replayOk']);
        $lines = file($this->projectDir.'/.hatfield/sessions/2/events.jsonl', \FILE_IGNORE_NEW_LINES);
        $this->assertCount(3, $lines);
    }

    public function testDryRunReportsStaleCancellation(): void
    {
        $this->writeStaleCancellationEvents();

        $result = $this->createService()->repair('2', false);
        $this->assertSame([], $result['duplicateSeqs']);
        $this->assertStringContainsString('stale non-terminal cancellation', $result['message']);
    }

    public function testApplyRepairsStaleCancellationAppendsTerminalEventsAndRebuildsCancelled(): void
    {
        $this->writeStaleCancellationEvents();
        $runStore = new InMemoryRunStore();
        $staleState = new RunState(
            runId: '2',
            status: RunStatus::Cancelling,
            version: 258,
            turnNo: 33,
            lastSeq: 6,
            activeStepId: 'follow_up-4470626110826',
        );
        $runStore->compareAndSwap($staleState, 0);

        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->method('rebuildIfStale')->willReturnCallback(function (RunState $state, string $runId) use ($runStore): RunStateReplayResult {
            $events = $this->readEventsFromDisk();
            $reducer = new RunStateReducer();
            $preparer = new ReplayEventPreparer();
            $sorted = $preparer->sortBySequence(array_map(
                static fn (array $payload) => (new EventPayloadNormalizer())->denormalizeRunEvent($payload),
                $events,
            ));
            $seed = new RunState(runId: $runId, status: RunStatus::Queued, version: 0, turnNo: 0, lastSeq: 0);
            $rebuilt = $reducer->replay($seed, array_filter($sorted));

            $runStore->compareAndSwap($rebuilt, $state->version);

            return RunStateReplayResult::rebuilt($rebuilt, $rebuilt->lastSeq, \count($sorted), true);
        });

        $service = $this->createService($runStore, $rebuilder);
        $result = $service->repair('2', true);

        $this->assertTrue($result['staleCancellationRepaired']);
        $this->assertGreaterThanOrEqual(1, $result['appendedTerminalEvents']);
        $this->assertStringContainsString('Stale cancellation terminalized to cancelled', $result['message']);
        $this->assertTrue($result['replayOk']);

        $lines = file($this->projectDir.'/.hatfield/sessions/2/events.jsonl', \FILE_IGNORE_NEW_LINES);
        $this->assertGreaterThanOrEqual(7, \count($lines));
        $last = json_decode((string) end($lines), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(RunEventTypeEnum::AgentEnd->value, $last['type']);
        $this->assertSame('cancelled', $last['payload']['reason']);

        $seqs = [];
        foreach ($lines as $line) {
            $payload = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            $seqs[] = $payload['seq'];
        }
        $this->assertSame(range(1, \count($seqs)), $seqs);

        $persisted = $runStore->get('2');
        $this->assertNotNull($persisted);
        $this->assertSame(RunStatus::Cancelled, $persisted->status);
        $this->assertNull($persisted->activeStepId);
    }

    public function testApplyRepairsStaleCancellationWhenToolExecutionNeverCompleted(): void
    {
        $this->writeStaleCancellationWithUnresolvedToolEvents();
        $runStore = new InMemoryRunStore();
        $staleState = new RunState(
            runId: '2',
            status: RunStatus::Cancelling,
            version: 258,
            turnNo: 33,
            lastSeq: 6,
            activeStepId: 'follow_up-4470626110826',
            pendingToolCalls: ['call_00_Mb5xDNF8BYpG5f1Hw5eT5643' => false],
        );
        $runStore->compareAndSwap($staleState, 0);

        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->method('rebuildIfStale')->willReturnCallback(function (RunState $state, string $runId) use ($runStore): RunStateReplayResult {
            $events = $this->readEventsFromDisk();
            $reducer = new RunStateReducer();
            $preparer = new ReplayEventPreparer();
            $sorted = $preparer->sortBySequence(array_map(
                static fn (array $payload) => (new EventPayloadNormalizer())->denormalizeRunEvent($payload),
                $events,
            ));
            $seed = new RunState(runId: $runId, status: RunStatus::Queued, version: 0, turnNo: 0, lastSeq: 0);
            $rebuilt = $reducer->replay($seed, array_filter($sorted));

            $runStore->compareAndSwap($rebuilt, $state->version);

            return RunStateReplayResult::rebuilt($rebuilt, $rebuilt->lastSeq, \count($sorted), true);
        });

        $service = $this->createService($runStore, $rebuilder);
        $result = $service->repair('2', true);

        $this->assertTrue($result['staleCancellationRepaired']);
        $this->assertGreaterThanOrEqual(1, $result['appendedTerminalEvents']);
        $this->assertStringContainsString('Stale cancellation terminalized to cancelled', $result['message']);
        $this->assertTrue($result['replayOk']);

        $lines = file($this->projectDir.'/.hatfield/sessions/2/events.jsonl', \FILE_IGNORE_NEW_LINES);
        $last = json_decode((string) end($lines), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertSame(RunEventTypeEnum::AgentEnd->value, $last['type']);
        $this->assertSame('cancelled', $last['payload']['reason']);

        $seqs = [];
        foreach ($lines as $line) {
            $payload = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            $seqs[] = $payload['seq'];
        }
        $this->assertSame(range(1, \count($seqs)), $seqs);

        $persisted = $runStore->get('2');
        $this->assertNotNull($persisted);
        $this->assertSame(RunStatus::Cancelled, $persisted->status);
        $this->assertNull($persisted->activeStepId);
    }

    private function writeStaleCancellationWithUnresolvedToolEvents(): void
    {
        $this->writeEvents([
            '{"schema_version":"1.0","run_id":"2","seq":1,"turn_no":0,"type":"agent_start","payload":{"messages":[]},"ts":"2026-07-09T01:00:00+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":2,"turn_no":33,"type":"agent_command_applied","payload":{"kind":"follow_up","payload":{"text":"run subagent"}},"ts":"2026-07-09T01:00:01+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":3,"turn_no":33,"type":"turn_advanced","payload":{"turn_no":33,"step_id":"follow_up-4470626110826"},"ts":"2026-07-09T01:00:02+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":4,"turn_no":33,"type":"llm_step_completed","payload":{"assistant_message":{"role":"assistant","content":null,"tool_calls":[{"id":"call_00_Mb5xDNF8BYpG5f1Hw5eT5643","type":"function","function":{"name":"subagent","arguments":"{}"}}]}},"ts":"2026-07-09T01:00:03+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":5,"turn_no":33,"type":"tool_execution_start","payload":{"tool_call_id":"call_00_Mb5xDNF8BYpG5f1Hw5eT5643","tool_name":"subagent"},"ts":"2026-07-09T01:00:04+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":6,"turn_no":33,"type":"agent_command_applied","payload":{"kind":"cancel"},"ts":"2026-07-09T01:00:05+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":7,"turn_no":33,"type":"command_rejected","payload":{"reason":"Command "follow_up" rejected because cancellation is in progress."},"ts":"2026-07-09T01:00:06+00:00"}',
        ]);
    }

    private function writeStaleCancellationEvents(): void
    {
        $this->writeEvents([
            '{"schema_version":"1.0","run_id":"2","seq":1,"turn_no":0,"type":"agent_start","payload":{"messages":[]},"ts":"2026-07-09T01:00:00+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":2,"turn_no":33,"type":"agent_command_applied","payload":{"kind":"follow_up","payload":{"text":"run subagent"}},"ts":"2026-07-09T01:00:01+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":3,"turn_no":33,"type":"turn_advanced","payload":{"turn_no":33,"step_id":"follow_up-4470626110826"},"ts":"2026-07-09T01:00:02+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":4,"turn_no":33,"type":"llm_step_completed","payload":{"assistant_message":{"role":"assistant","content":null,"tool_calls":[{"id":"call_00_Mb5xDNF8BYpG5f1Hw5eT5643","type":"function","function":{"name":"subagent","arguments":"{}"}}]}},"ts":"2026-07-09T01:00:03+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":5,"turn_no":33,"type":"tool_execution_end","payload":{"tool_call_id":"call_00_Mb5xDNF8BYpG5f1Hw5eT5643","tool_name":"subagent","success":true},"ts":"2026-07-09T01:00:04+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":6,"turn_no":33,"type":"agent_command_applied","payload":{"kind":"cancel"},"ts":"2026-07-09T01:00:05+00:00"}',
            '{"schema_version":"1.0","run_id":"2","seq":7,"turn_no":33,"type":"command_rejected","payload":{"reason":"Command \\"follow_up\\" rejected because cancellation is in progress."},"ts":"2026-07-09T01:00:06+00:00"}',
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function readEventsFromDisk(): array
    {
        $lines = file($this->projectDir.'/.hatfield/sessions/2/events.jsonl', \FILE_IGNORE_NEW_LINES);
        $out = [];
        foreach ($lines as $line) {
            $out[] = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
        }

        return $out;
    }

    private function createService(?InMemoryRunStore $runStore = null, ?RunStateRebuilderInterface $rebuilder = null): SessionRepairService
    {
        $appConfig = new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig(), cwd: $this->projectDir);
        $sessionStore = new HatfieldSessionStore($appConfig, $this->createStub(\Doctrine\ORM\EntityManagerInterface::class));

        return new SessionRepairService(
            sessionStore: $sessionStore,
            runStore: $runStore ?? new InMemoryRunStore(),
            runStateRebuilder: $rebuilder ?? $this->createStub(RunStateRebuilderInterface::class),
            replayEventPreparer: new ReplayEventPreparer(),
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockManager: new RunLockManager(new LockFactory(new FlockStore())),
            runStateReducer: new RunStateReducer(),
            eventFactory: new EventFactory(),
            logger: new NullLogger(),
        );
    }

    /** @param list<string> $lines */
    private function writeEvents(array $lines): void
    {
        file_put_contents($this->projectDir.'/.hatfield/sessions/2/events.jsonl', implode("\n", $lines)."\n");
    }

    private function rmDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->rmDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
