<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Command;

use Ineersa\AgentCore\Api\Serializer\RunEventSerializer;
use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\RunDebugService;
use Ineersa\AgentCore\Command\AgentLoopRunInspectCommand;
use Ineersa\AgentCore\Command\AgentLoopRunRebuildHotStateCommand;
use Ineersa\AgentCore\Command\AgentLoopRunReplayCommand;
use Ineersa\AgentCore\Command\AgentLoopRunTailCommand;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunEventStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class AgentLoopRunDebugCommandsTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/agent-core-debug-cmd-'.uniqid('', true);
        mkdir($this->basePath, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
    }

    public function testRunInspectJsonShowsStateIntegrityAndPendingCommands(): void
    {
        $fixture = $this->createFixture();

        self::assertTrue($fixture->runStore->compareAndSwap(new RunState(
            runId: 'run-debug-1',
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 2,
            activeStepId: 'turn-1-llm-1',
        ), expectedVersion: 0));

        self::assertTrue($fixture->commandStore->enqueue(new PendingCommand(
            runId: 'run-debug-1',
            kind: 'steer',
            idempotencyKey: 'steer-1',
            payload: ['message' => 'nudge'],
        )));

        $fixture->eventStore->append(new RunEvent(
            runId: 'run-debug-1',
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: ['step_id' => 'start-1'],
        ));

        $command = new AgentLoopRunInspectCommand($fixture->runDebugService);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([
            'runId' => 'run-debug-1',
            '--json' => true,
        ]));

        $payload = $this->decodeJson($tester->getDisplay());

        self::assertTrue($payload['exists']);
        self::assertSame('running', $payload['state']['status']);
        self::assertSame(1, $payload['integrity']['event_count']);
        self::assertCount(1, $payload['pending_commands']);
    }

    public function testRunReplayJsonRespectsSequenceCursorAndOrdering(): void
    {
        $fixture = $this->createFixture();

        $fixture->eventStore->append(new RunEvent(
            runId: 'run-debug-2',
            seq: 2,
            turnNo: 1,
            type: 'agent_end',
            payload: ['reason' => 'ok'],
        ));

        $fixture->eventStore->append(new RunEvent(
            runId: 'run-debug-2',
            seq: 1,
            turnNo: 0,
            type: 'run_started',
            payload: [],
        ));

        $command = new AgentLoopRunReplayCommand($fixture->runDebugService, new RunEventSerializer());
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([
            'runId' => 'run-debug-2',
            '--after-seq' => '1',
            '--limit' => '20',
            '--json' => true,
        ]));

        $payload = $this->decodeJson($tester->getDisplay());

        self::assertSame('canonical_events', $payload['source']);
        self::assertSame(1, $payload['total_events']);
        self::assertCount(1, $payload['events']);
        self::assertSame(2, $payload['events'][0]['seq']);
        self::assertSame('agent_end', $payload['events'][0]['type']);
    }

    public function testRunRebuildHotStateJsonPersistsPromptSnapshot(): void
    {
        $fixture = $this->createFixture();

        $fixture->eventStore->append(new RunEvent(
            runId: 'run-debug-3',
            seq: 1,
            turnNo: 1,
            type: 'assistant_message_committed',
            payload: [
                'message' => [
                    'role' => 'assistant',
                    'content' => [[
                        'type' => 'text',
                        'text' => 'hello world',
                    ]],
                ],
            ],
        ));

        $command = new AgentLoopRunRebuildHotStateCommand($fixture->runDebugService);
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([
            'runId' => 'run-debug-3',
            '--json' => true,
        ]));

        $payload = $this->decodeJson($tester->getDisplay());

        self::assertSame('canonical_events', $payload['source']);
        self::assertSame(1, $payload['event_count']);
        self::assertNotNull($fixture->promptStateStore->get('run-debug-3'));
    }

    public function testRunTailJsonReturnsLatestWindow(): void
    {
        $fixture = $this->createFixture();

        $fixture->eventStore->append(new RunEvent('run-debug-4', 1, 0, 'run_started', []));
        $fixture->eventStore->append(new RunEvent('run-debug-4', 2, 1, 'agent_step_start', []));
        $fixture->eventStore->append(new RunEvent('run-debug-4', 3, 1, 'agent_step_end', []));

        $command = new AgentLoopRunTailCommand($fixture->runDebugService, new RunEventSerializer());
        $tester = new CommandTester($command);

        self::assertSame(0, $tester->execute([
            'runId' => 'run-debug-4',
            '--limit' => '2',
            '--json' => true,
        ]));

        $payload = $this->decodeJson($tester->getDisplay());

        self::assertSame('canonical_events', $payload['source']);
        self::assertCount(2, $payload['events']);
        self::assertSame(2, $payload['events'][0]['seq']);
        self::assertSame(3, $payload['events'][1]['seq']);
    }

    /**
     * Decodes command JSON output into an associative array.
     *
     * @return array<string, mixed>
     */
    private function decodeJson(string $json): array
    {
        $decoded = json_decode($json, true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function createFixture(): RunDebugCommandFixture
    {
        $filesystem = new Filesystem(new LocalFilesystemAdapter($this->basePath));

        $runStore = new InMemoryRunStore();
        $eventStore = new RunEventStore();
        $commandStore = new InMemoryCommandStore();
        $promptStateStore = new InMemoryPromptStateStore();
        $runLogReader = new RunLogReader($filesystem);

        $runDebugService = new RunDebugService(
            runStore: $runStore,
            commandStore: $commandStore,
            promptStateStore: $promptStateStore,
            eventStore: $eventStore,
            runLogReader: $runLogReader,
            replayService: new ReplayService($eventStore, $runLogReader, $promptStateStore),
        );

        return new RunDebugCommandFixture(
            runDebugService: $runDebugService,
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: $commandStore,
            promptStateStore: $promptStateStore,
        );
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());

                continue;
            }

            unlink($item->getPathname());
        }

        rmdir($path);
    }
}

final readonly class RunDebugCommandFixture
{
    public function __construct(
        public RunDebugService $runDebugService,
        public InMemoryRunStore $runStore,
        public RunEventStore $eventStore,
        public InMemoryCommandStore $commandStore,
        public InMemoryPromptStateStore $promptStateStore,
    ) {
    }
}
