<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeNodeView;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
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
            $provider = $this->createStub(TurnTreeProviderInterface::class);
            $provider->method('forSession')->willReturn($this->sampleTree($sessionId));

            $runtime = $this->buildTuiContext()
                ->withTui($harness->tui())
                ->withScreen($harness->screen())
                ->withState(new TuiSessionState($sessionId))
                ->withTurnTreeProvider($provider)
                ->build();

            $picker = new FileRewindPickerController($this->makeService($projectDir));
            $picker->wire(new BridgeTuiExtensionContext($runtime));
            $picker->open($sessionId);

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

    private function sampleTree(string $sessionId): TurnTreeView
    {
        return new TurnTreeView(
            runId: $sessionId,
            nodesByTurnNo: [
                1 => new TurnTreeNodeView(1, null, [2], 2, 'Create file', 'Hey', null, false, 'user'),
                2 => new TurnTreeNodeView(2, 1, [3], 4, 'Edit file', 'Follow', null, false, 'assistant'),
                3 => new TurnTreeNodeView(3, 2, [], 6, 'Append line', 'Third', null, true, 'user'),
            ],
            rootTurnNos: [1],
            currentLeafTurnNo: 3,
            activePathTurnNos: [1, 2, 3],
        );
    }
}
