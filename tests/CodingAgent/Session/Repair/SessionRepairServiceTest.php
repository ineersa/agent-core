<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Repair;

use Ineersa\AgentCore\Application\Dto\RunStateReplayResult;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface;
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
