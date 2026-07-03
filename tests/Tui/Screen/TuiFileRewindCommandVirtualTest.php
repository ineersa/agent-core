<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindCheckpointKindEnum;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindCommandHandler;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindConfig;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindLedgerProjector;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindLedgerStore;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindService;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindTuiActionHandler;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\GitProcessRunner;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\HiddenGitSnapshotBackend;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\RewindPathScope;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\RewindProjectIdentity;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\RewindStoragePaths;
use Ineersa\CodingAgent\Runtime\Contract\FileRewindConversationRewindBindPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\ConversationRewindPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeNodeView;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
use Ineersa\CodingAgent\Tests\Extension\InMemoryExtensionApiBridge;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindActionEnum;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindActionHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindInteractiveRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindPreviewEntryDTO;
use Ineersa\Hatfield\ExtensionApi\Command\FileRewindPreviewProviderInterface;
use Ineersa\Hatfield\ExtensionApi\Command\InteractiveCommandHostInterface;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Extension\ExtensionSlashCommandHandler;
use Ineersa\Tui\Extension\TuiInteractiveCommandHost;
use Ineersa\Tui\Listener\RewindCommandRegistrar;
use Ineersa\Tui\Listener\TreeCommandHandler;
use Ineersa\Tui\Picker\FileRewindPickerController;
use Ineersa\Tui\Picker\TreePickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\FileRewind\TuiFileRewindPickerFlow;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TuiFileRewindCommandVirtualTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;


    #[Test]
    public function testRewindPickerOpensViaRegistrarAndPickerFlow(): void
    {
        $sessionId = 'rewind-picker-session';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState(new TuiSessionState($sessionId))
            ->withScreen($harness->screen())
            ->build();

        $treeProvider = $this->createStub(TurnTreeProviderInterface::class);
        $treeProvider->method('forSession')->willReturn($this->sampleTree($sessionId));

        $preview = new class implements FileRewindPreviewProviderInterface {
            public function hasCheckpointForTurn(string $runId, int $turnNo): bool
            {
                return 1 === $turnNo;
            }

            public function previewForTurn(string $runId, int $turnNo): array
            {
                return [new FileRewindPreviewEntryDTO('a.txt', 'modified', 2, 1, false, false)];
            }
        };
        $action = new class implements FileRewindActionHandlerInterface {
            public function execute(string $sessionId, int $turnNo, FileRewindActionEnum $action): void {}
        };
        $bindPort = new class($preview, $action) implements FileRewindConversationRewindBindPortInterface {
            public function __construct(
                private FileRewindPreviewProviderInterface $preview,
                private FileRewindActionHandlerInterface $action,
            ) {}
            public function bindConversationRewind(?ConversationRewindPortInterface $port): void {}
        };
        $previewPort = new class($preview) implements \Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnPreviewPortInterface {
            public function __construct(private FileRewindPreviewProviderInterface $preview) {}
            public function hasCheckpoint(string $sessionId, int $turnNo): bool { return $this->preview->hasCheckpointForTurn($sessionId, $turnNo); }
            public function preview(string $sessionId, int $turnNo): array {
                $rows = [];
                foreach ($this->preview->previewForTurn($sessionId, $turnNo) as $entry) {
                    $rows[] = ['path' => $entry->path, 'status' => $entry->status, 'added' => $entry->addedLines, 'removed' => $entry->removedLines];
                }
                return $rows;
            }
        };
        $actionPort = new class($action) implements \Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnActionPortInterface {
            public function __construct(private FileRewindActionHandlerInterface $action) {}
            public function execute(string $sessionId, int $turnNo, string $action): void { $this->action->execute($sessionId, $turnNo, FileRewindActionEnum::from($action)); }
        };

        $picker = new FileRewindPickerController($treeProvider, $previewPort, $actionPort);
        $pickerFlow = new TuiFileRewindPickerFlow();
        (new RewindCommandRegistrar(
            $picker,
            $bindPort,
            $pickerFlow,
            $this->createStub(TuiSessionSwitchServiceInterface::class),
        ))->register($context);

        (new TuiInteractiveCommandHost($pickerFlow))->openFileRewindPicker(
            new FileRewindInteractiveRequestDTO($sessionId, $preview, $action),
        );

        self::assertTrue($picker->isOpen());
    }

    #[Test]
    public function testTreeCommandOpensConversationOnlyPickerWithoutFileRestoreMenu(): void
    {
        $sessionId = 'tree-only-session';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn($this->sampleTree($sessionId));
        $treePicker = new TreePickerController($provider, $this->createStub(TuiSessionSwitchServiceInterface::class));
        $treePicker->setRuntimeRefs($harness->tui(), $harness->screen(), new TuiSessionState($sessionId));

        (new TreeCommandHandler($treePicker))->handle(new SlashCommand('tree', '', '/tree'));

        self::assertTrue($treePicker->isOpen());
        $screen = $harness->plainScreenText();
        self::assertStringContainsString('Enter to rewind', $screen);
        self::assertStringNotContainsString('Restore files to this turn', $screen);
        self::assertStringNotContainsString('Undo last file restore', $screen);
        self::assertStringNotContainsString('File rewind', $screen);
    }

    #[Test]
    public function testCombinedActionRestoresFilesBeforeConversationRewind(): void
    {
        $runner = new GitProcessRunner();
        if (!$runner->isGitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        $order = [];
        $dir = TestDirectoryIsolation::createProjectTempDir('rewind-combined');
        try {
            $service = $this->makeOperationalFileRewindService($runner, $dir);
            $identity = RewindProjectIdentity::fromProjectRoot($dir);
            $backend = new HiddenGitSnapshotBackend($runner, new NullLogger());
            $paths = new RewindStoragePaths($dir);
            $scope = new RewindPathScope($dir);
            $gitDir = $paths->hiddenGitDir($identity);
            $idx = $paths->tmpDir($identity).'/cap.index';
            @mkdir(dirname($idx), 0700, true);
            file_put_contents($dir.'/f.txt', "v1\n");
            $tree1 = $backend->captureTreeSha($gitDir, $dir, $idx, $scope);
            $commit1 = $backend->treeShaToCommitSha($gitDir, $dir, $tree1, 't1');
            (new FileRewindLedgerStore($dir))->appendCheckpoint($identity, [
                'run_id' => 'sess',
                'turn_no' => 2,
                'anchor_seq' => 1,
                'kind' => FileRewindCheckpointKindEnum::TurnBoundary->value,
                'project_hash' => $identity->projectHash,
                'snapshot_commit_sha' => $commit1,
            ]);
            file_put_contents($dir.'/f.txt', "v2\n");

            $conversation = new class($order) implements ConversationRewindPortInterface {
                public function __construct(private array &$order) {}
                public function rewindToTurn(int $turnNo): void
                {
                    $this->order[] = 'conversation:'.$turnNo;
                }
            };
            $handler = new FileRewindTuiActionHandler($service);
            $handler->bindConversationRewind($conversation);
            $handler->execute('sess', 2, FileRewindActionEnum::RestoreFilesAndConversation);

            self::assertSame("v1\n", file_get_contents($dir.'/f.txt'));
            self::assertSame(['conversation:2'], $order);
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    #[Test]
    public function testCombinedActionSkipsConversationWhenRestoreFails(): void
    {
        $runner = new GitProcessRunner();
        if (!$runner->isGitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        $dir = TestDirectoryIsolation::createProjectTempDir('rewind-fail-combined');
        try {
            $service = $this->makeOperationalFileRewindService($runner, $dir);
            $identity = RewindProjectIdentity::fromProjectRoot($dir);
            (new FileRewindLedgerStore($dir))->appendCheckpoint($identity, [
                'run_id' => 'sess',
                'turn_no' => 2,
                'anchor_seq' => 1,
                'kind' => FileRewindCheckpointKindEnum::TurnBoundary->value,
                'project_hash' => $identity->projectHash,
                'snapshot_commit_sha' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef',
            ]);
            $conversation = $this->createMock(ConversationRewindPortInterface::class);
            $conversation->expects(self::never())->method('rewindToTurn');
            $handler = new FileRewindTuiActionHandler($service);
            $handler->bindConversationRewind($conversation);

            try {
                $handler->execute('sess', 2, FileRewindActionEnum::RestoreFilesAndConversation);
                self::fail('Expected restore failure');
            } catch (\RuntimeException) {
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }

    #[Test]
    public function testCombinedActionUndoesFilesWhenConversationRewindFailsAfterRestore(): void
    {
        $runner = new GitProcessRunner();
        if (!$runner->isGitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        $dir = TestDirectoryIsolation::createProjectTempDir('rewind-undo-conv-fail');
        try {
            $order = [];
            $service = $this->makeOperationalFileRewindService($runner, $dir);
            $scope = new RewindPathScope($dir);
            $identity = RewindProjectIdentity::fromProjectRoot($dir);
            $paths = new RewindStoragePaths($dir);
            $backend = new HiddenGitSnapshotBackend($runner, new NullLogger());
            $gitDir = $paths->hiddenGitDir($identity);
            $idx = $paths->tmpDir($identity).'/cap.index';
            file_put_contents($dir.'/f.txt', "v1\n");
            $backend->captureTreeSha($gitDir, $dir, $idx, $scope);
            $tree1 = $backend->captureTreeSha($gitDir, $dir, $idx, $scope);
            $commit1 = $backend->treeShaToCommitSha($gitDir, $dir, $tree1, 't1');
            (new FileRewindLedgerStore($dir))->appendCheckpoint($identity, [
                'run_id' => 'sess',
                'turn_no' => 2,
                'anchor_seq' => 1,
                'kind' => FileRewindCheckpointKindEnum::TurnBoundary->value,
                'project_hash' => $identity->projectHash,
                'snapshot_commit_sha' => $commit1,
            ]);
            file_put_contents($dir.'/f.txt', "v2\n");

            $conversation = new class($order) implements ConversationRewindPortInterface {
                public function __construct(private array &$order) {}
                public function rewindToTurn(int $turnNo): void
                {
                    $this->order[] = 'conversation:'.$turnNo;
                    throw new \RuntimeException('conversation rewind failed');
                }
            };
            $handler = new FileRewindTuiActionHandler($service);
            $handler->bindConversationRewind($conversation);

            try {
                $handler->execute('sess', 2, FileRewindActionEnum::RestoreFilesAndConversation);
                self::fail('Expected combined action failure');
            } catch (\RuntimeException $e) {
                self::assertStringContainsString('conversation rewind failed', $e->getMessage());
                self::assertStringContainsString('undone', strtolower($e->getMessage()));
            }

            self::assertSame("v2\n", file_get_contents($dir.'/f.txt'));
            self::assertSame(['conversation:2'], $order);
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }


    #[Test]
    public function testRewindSlashCommandRegisteredViaExtensionManager(): void
    {
        $slashRegistry = new \Ineersa\Tui\Command\SlashCommandRegistry();
        $appConfig = new \Ineersa\CodingAgent\Config\AppConfig(
            tui: new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'default'),
            logging: new \Ineersa\CodingAgent\Config\LoggingConfig(),
            extensions: new \Ineersa\CodingAgent\Config\ExtensionsConfig(
                enabled: [\Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindExtension::class],
                settings: ['file_rewind' => ['enabled' => true]],
            ),
            cwd: \Ineersa\CodingAgent\Tests\Support\ProjectDir::get(),
        );
        $bridge = new \Ineersa\CodingAgent\Extension\ExtensionToolRegistryBridge(
            new \Ineersa\CodingAgent\Tool\ToolRegistry(),
            new \Ineersa\CodingAgent\Extension\ExtensionHookRegistry(),
            $appConfig,
            new \Ineersa\CodingAgent\Extension\ExtensionExecBridge(),
            new \Ineersa\Tui\Extension\TuiCommandRegistryAdapter($slashRegistry),
            new \Ineersa\CodingAgent\Extension\FileRewind\FileRewindRuntimePorts(),
        );
        $diagnostics = (new \Ineersa\CodingAgent\Extension\ExtensionManager($appConfig, $bridge, new NullLogger()))->loadExtensions();
        self::assertSame([], $diagnostics);
        self::assertTrue($slashRegistry->has('rewind'));
        $result = $slashRegistry->execute(new SlashCommand('rewind', '', '/rewind'), 'rewind-picker-session');
        self::assertNotInstanceOf(NoOp::class, $result);
    }

    private function sampleTree(string $runId): TurnTreeView
    {
        return new TurnTreeView(
            runId: $runId,
            nodesByTurnNo: [
                1 => new TurnTreeNodeView(
                    turnNo: 1,
                    parentTurnNo: null,
                    childTurnNos: [2],
                    anchorSeq: 2,
                    title: 'Turn 1',
                    promptPreview: 'first',
                    createdAt: null,
                    isCurrentLeaf: false,
                ),
                2 => new TurnTreeNodeView(
                    turnNo: 2,
                    parentTurnNo: 1,
                    childTurnNos: [],
                    anchorSeq: 4,
                    title: 'Turn 2',
                    promptPreview: 'second',
                    createdAt: null,
                    isCurrentLeaf: true,
                ),
            ],
            rootTurnNos: [1],
            currentLeafTurnNo: 2,
            activePathTurnNos: [1, 2],
        );
    }

    private function makeOperationalFileRewindService(GitProcessRunner $runner, ?string $dir = null): FileRewindService
    {
        $dir ??= TestDirectoryIsolation::createProjectTempDir('rewind-service');
        file_put_contents($dir.'/probe.txt', "ok\n");

        return new FileRewindService(
            backend: new HiddenGitSnapshotBackend($runner, new NullLogger()),
            gitRunner: $runner,
            paths: new RewindStoragePaths($dir),
            ledgerStore: new FileRewindLedgerStore($dir),
            ledgerProjector: new FileRewindLedgerProjector(),
            config: FileRewindConfig::fromSettings(['enabled' => true]),
            logger: new NullLogger(),
            projectCwd: $dir,
        );
    }
}
