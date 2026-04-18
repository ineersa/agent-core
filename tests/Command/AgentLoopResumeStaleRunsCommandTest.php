<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Command;

use Ineersa\AgentCore\Application\Handler\ReplayService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Command\AgentLoopResumeStaleRunsCommand;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryPromptStateStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunEventStore;
use Ineersa\AgentCore\Infrastructure\Storage\RunLogReader;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class AgentLoopResumeStaleRunsCommandTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir().'/agent-core-resume-cmd-'.uniqid('', true);
        mkdir($this->basePath, recursive: true);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
    }

    public function testResumesStaleRunningRunsAndRebuildsMissingHotState(): void
    {
        $runStore = new InMemoryRunStore();
        self::assertTrue($runStore->compareAndSwap(new RunState(
            runId: 'run-stale-1',
            status: RunStatus::Running,
            version: 1,
            turnNo: 2,
            lastSeq: 4,
        ), expectedVersion: 0));

        sleep(2);

        $eventStore = new RunEventStore();
        $promptStateStore = new InMemoryPromptStateStore();
        $replayService = new ReplayService(
            $eventStore,
            new RunLogReader(new Filesystem(new LocalFilesystemAdapter($this->basePath))),
            $promptStateStore,
        );

        $commandBus = new ResumeCommandRecordingBus();

        $command = new AgentLoopResumeStaleRunsCommand(
            runStore: $runStore,
            promptStateStore: $promptStateStore,
            replayService: $replayService,
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            commandBus: $commandBus,
            staleAfterSeconds: 1,
        );

        $tester = new CommandTester($command);
        self::assertSame(0, $tester->execute([]));

        $advanceMessages = array_values(array_filter(
            $commandBus->messages,
            static fn (object $message): bool => $message instanceof \Ineersa\AgentCore\Domain\Message\AdvanceRun,
        ));

        self::assertCount(1, $advanceMessages);
        self::assertNotNull($promptStateStore->get('run-stale-1'));
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

final class ResumeCommandRecordingBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $messages = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->messages[] = $message;

        return new Envelope($message, $stamps);
    }
}
