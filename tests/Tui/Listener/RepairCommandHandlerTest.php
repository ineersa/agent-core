<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

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
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\RepairCommandHandler;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[AllowMockObjectsWithoutExpectations]
final class RepairCommandHandlerTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir().'/hatfield-repair-handler-'.getmypid();
        mkdir($this->projectDir.'/.hatfield/sessions/2', 0777, true);
        file_put_contents(
            $this->projectDir.'/.hatfield/sessions/2/events.jsonl',
            '{"schema_version":"1.0","run_id":"2","seq":345,"turn_no":29,"type":"agent_command_applied","payload":{"kind":"cancel"},"ts":"2026-07-09T01:55:42+00:00"}'."\n".
            '{"schema_version":"1.0","run_id":"2","seq":345,"turn_no":29,"type":"tool_execution_update","payload":{"tool_name":"fork"},"ts":"2026-07-09T01:55:42+00:00"}'."\n",
        );
    }

    protected function tearDown(): void
    {
        if (is_dir($this->projectDir)) {
            $this->rmDir($this->projectDir);
        }
    }

    public function testRepairCommandAppliesRepair(): void
    {
        $state = new TuiSessionState('2');
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(runId: '2', status: RunStatus::Failed, version: 1, lastSeq: 345), 0);
        $rebuilder = $this->createMock(RunStateRebuilderInterface::class);
        $rebuilder->method('rebuildIfStale')->willReturn(RunStateReplayResult::rebuilt(
            new RunState(runId: '2', status: RunStatus::Cancelled, version: 2, lastSeq: 2),
            2,
            2,
            true,
        ));
        $repair = $this->createRepairService($runStore, $rebuilder);
        $result = (new RepairCommandHandler($state, $repair))->handle(new SlashCommand('repair', '', '/repair'));
        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Session repaired', $result->text);
        $this->assertStringNotContainsString('/repair apply', $result->text);
        $this->assertStringNotContainsString('Repair preview', $result->text);
    }

    private function createRepairService(?InMemoryRunStore $runStore = null, ?RunStateRebuilderInterface $rebuilder = null): SessionRepairService
    {
        $appConfig = new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig(), cwd: $this->projectDir);

        return new SessionRepairService(
            sessionStore: new HatfieldSessionStore($appConfig, $this->createStub(\Doctrine\ORM\EntityManagerInterface::class)),
            runStore: $runStore ?? new InMemoryRunStore(),
            runStateRebuilder: $rebuilder ?? $this->createStub(RunStateRebuilderInterface::class),
            replayEventPreparer: new ReplayEventPreparer(),
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockManager: new RunLockManager(new LockFactory(new FlockStore())),
            logger: new NullLogger(),
        );
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
