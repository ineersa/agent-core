<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryPromptView;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\FileRewind\FileRewindCheckpointKindEnum;
use Ineersa\HatfieldExt\FileRewind\FileRewindConfig;
use Ineersa\HatfieldExt\FileRewind\FileRewindLedgerProjector;
use Ineersa\HatfieldExt\FileRewind\FileRewindLedgerStore;
use Ineersa\HatfieldExt\FileRewind\FileRewindPickerController;
use Ineersa\HatfieldExt\FileRewind\FileRewindService;
use Ineersa\HatfieldExt\FileRewind\GitProcessRunner;
use Ineersa\HatfieldExt\FileRewind\HiddenGitSnapshotBackend;
use Ineersa\HatfieldExt\FileRewind\RewindPathScope;
use Ineersa\HatfieldExt\FileRewind\RewindProjectIdentity;
use Ineersa\HatfieldExt\FileRewind\RewindStoragePaths;
use Ineersa\Tui\Picker\HistoryPickerController;
use Ineersa\Tui\Runtime\BridgeTuiExtensionContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TuiFileRewindPickerExtensionVirtualTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private GitProcessRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new GitProcessRunner();
        if (!$this->runner->isGitAvailable()) {
            $this->markTestSkipped('git not available');
        }
    }

    #[Test]
    public function testExtensionPickerRendersCheckpointBackedRowsViaGenericTuiContext(): void
    {
        $sessionId = 'rewind-ext-virtual';
        $projectDir = TestDirectoryIsolation::createProjectTempDir('rewind-ext-picker');
        try {
            $this->seedCheckpoint($projectDir, $sessionId, 1);
            $this->seedCheckpoint($projectDir, $sessionId, 3);

            $harness = new VirtualTuiHarness(sessionId: $sessionId);
            $provider = $this->createStub(HistoryProviderInterface::class);
            $provider->method('forSession')->willReturn($this->sampleTree($sessionId));

            $runtime = $this->buildTuiContext()
                ->withTui($harness->tui())
                ->withScreen($harness->screen())
                ->withState(new TuiSessionState($sessionId))
                ->withHistoryProvider($provider)
                ->build();

            $picker = new FileRewindPickerController($this->makeService($projectDir));
            $picker->wire(new BridgeTuiExtensionContext($runtime));
            $picker->open();

            $screen = $harness->plainScreenText();
            $this->assertSame(1, substr_count($screen, 'Checkpoint turn 3:'));
            $this->assertStringContainsString('checkpoint 1:', $screen);
            $this->assertStringContainsString('checkpoint 3:', $screen);
            $this->assertStringNotContainsString('checkpoint 2:', $screen);
            $this->assertStringNotContainsString('Restore files + conversation', $screen);
        } finally {
            TestDirectoryIsolation::removeDirectory($projectDir);
        }
    }

    /**
     * Thesis: public ExtensionApi turnRowsInDisplayOrder and /history both expose only
     * retained user-prompt rows; assistant/tool-cycle turns stay internal.
     */
    #[Test]
    public function testTurnRowsInDisplayOrderAndHistoryAreUserPromptOnly(): void
    {
        $sessionId = 'rewind-ext-rows';
        $tree = $this->sampleTree($sessionId);

        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $provider = $this->createStub(HistoryProviderInterface::class);
        $provider->method('forSession')->willReturn($tree);

        $runtime = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withScreen($harness->screen())
            ->withState(new TuiSessionState($sessionId))
            ->withHistoryProvider($provider)
            ->build();

        $bridge = new BridgeTuiExtensionContext($runtime);
        $rows = $bridge->turnRowsInDisplayOrder($sessionId);

        $this->assertSame([1, 3], array_column($rows, 'turnNo'));
        $this->assertSame(['user', 'user'], array_column($rows, 'displayRole'));
        $this->assertNotContains(2, array_column($rows, 'turnNo'), 'assistant/tool-cycle turn must not be a public row');

        $historyTurnNos = HistoryPickerController::userPromptTurnNos($tree);
        $this->assertSame([1, 3], $historyTurnNos);
        $this->assertNotContains(2, $historyTurnNos, '/history must stay user-prompt-only');
    }

    private function seedCheckpoint(string $projectDir, string $runId, int $turnNo): void
    {
        $identity = RewindProjectIdentity::fromProjectRoot($projectDir);
        $ledger = new FileRewindLedgerStore($projectDir);
        $backend = new HiddenGitSnapshotBackend($this->runner, new NullLogger());
        $paths = new RewindStoragePaths($projectDir);
        $gitDir = $paths->hiddenGitDir($identity);
        $scope = new RewindPathScope($projectDir);
        $idx = $paths->tmpDir($identity).'/cap-'.$turnNo.'.index';
        @mkdir(\dirname($idx), 0700, true);
        file_put_contents($projectDir.'/turn-'.$turnNo.'.txt', "state-{$turnNo}\n");
        $treeSha = $backend->captureTreeSha($gitDir, $projectDir, $idx, $scope);
        $commitSha = $backend->treeShaToCommitSha($gitDir, $projectDir, $treeSha, 'turn'.$turnNo);
        $ledger->appendCheckpoint($identity, [
            'run_id' => $runId,
            'turn_no' => $turnNo,
            'anchor_seq' => $turnNo,
            'kind' => FileRewindCheckpointKindEnum::TurnBoundary->value,
            'project_hash' => $identity->projectHash,
            'snapshot_commit_sha' => $commitSha,
        ]);
    }

    private function makeService(string $projectDir): FileRewindService
    {
        return new FileRewindService(
            backend: new HiddenGitSnapshotBackend($this->runner, new NullLogger()),
            gitRunner: $this->runner,
            paths: new RewindStoragePaths($projectDir),
            ledgerStore: new FileRewindLedgerStore($projectDir),
            ledgerProjector: new FileRewindLedgerProjector(),
            config: new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1_048_576),
            logger: new NullLogger(),
            projectCwd: $projectDir,
        );
    }

    private function sampleTree(string $sessionId): HistoryView
    {
        return new HistoryView(
            runId: $sessionId,
            turns: [
                new HistoryPromptView(1, 'Create file', 'user', '', false),
                new HistoryPromptView(2, 'Edit file', 'assistant', '', false),
                new HistoryPromptView(3, 'Append line', 'user', '', true),
            ],
            positionTurnNo: 3,
        );
    }
}
