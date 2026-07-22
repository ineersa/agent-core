<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitEventSummaryDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookContextDTO;
use Ineersa\HatfieldExt\FileRewind\FileRewindAfterTurnCommitHook;
use Ineersa\HatfieldExt\FileRewind\FileRewindConfig;
use Ineersa\HatfieldExt\FileRewind\FileRewindLedgerProjector;
use Ineersa\HatfieldExt\FileRewind\FileRewindLedgerStore;
use Ineersa\HatfieldExt\FileRewind\FileRewindService;
use Ineersa\HatfieldExt\FileRewind\GitProcessRunner;
use Ineersa\HatfieldExt\FileRewind\HiddenGitSnapshotBackend;
use Ineersa\HatfieldExt\FileRewind\RewindStoragePaths;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FileRewindPostToolCheckpointRestoreTest extends TestCase
{
    private string $projectDir;
    private FileRewindService $service;
    private FileRewindAfterTurnCommitHook $hook;

    protected function setUp(): void
    {
        $runner = new GitProcessRunner();
        if (!$runner->isGitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('file-rewind-post-tool');
        $paths = new RewindStoragePaths($this->projectDir);
        $this->service = new FileRewindService(
            backend: new HiddenGitSnapshotBackend($runner, new NullLogger()),
            gitRunner: $runner,
            paths: $paths,
            ledgerStore: new FileRewindLedgerStore($this->projectDir),
            ledgerProjector: new FileRewindLedgerProjector(),
            config: new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1_048_576),
            logger: new NullLogger(),
            projectCwd: $this->projectDir,
        );
        $this->hook = new FileRewindAfterTurnCommitHook(
            $this->service,
            new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1_048_576),
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testRestoreFromPostToolCheckpointReturnsOneLineAfterSecondLineAdded(): void
    {
        $runId = 'session-post-tool';
        file_put_contents($this->projectDir.'/test.txt', "LINE_ONE\n");

        $this->hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: $runId,
            turnNo: 2,
            status: 'running',
            events: [new AfterTurnCommitEventSummaryDTO(12, 'llm_step_completed')],
            effectsCount: 0,
        ));

        $this->assertTrue($this->service->hasCheckpointForTurn($runId, 2));

        file_put_contents($this->projectDir.'/test.txt', "LINE_ONE\nLINE_TWO\n");

        $this->service->restoreForTurn($runId, 2);

        $this->assertSame("LINE_ONE\n", file_get_contents($this->projectDir.'/test.txt'));
        $this->assertFileExists($this->projectDir.'/test.txt');
    }

    public function testLiveLikeToolCycleSequenceRestoresOneLineAfterAppend(): void
    {
        $runId = '1';
        file_put_contents($this->projectDir.'/test.txt', "LINE_ONE\n");

        $this->hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: $runId,
            turnNo: 1,
            status: 'running',
            events: [new AfterTurnCommitEventSummaryDTO(11, 'tool_batch_committed')],
            effectsCount: 0,
        ));

        file_put_contents($this->projectDir.'/test.txt', "LINE_ONE\nLINE_TWO\n");

        $this->hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: $runId,
            turnNo: 2,
            status: 'running',
            events: [new AfterTurnCommitEventSummaryDTO(21, 'tool_batch_committed')],
            effectsCount: 0,
        ));

        $this->hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: $runId,
            turnNo: 3,
            status: 'completed',
            events: [new AfterTurnCommitEventSummaryDTO(25, 'agent_end')],
            effectsCount: 0,
        ));

        $this->assertTrue($this->service->hasCheckpointForTurn($runId, 1), 'post-create tool_batch should checkpoint one-line state');
        $this->assertTrue($this->service->hasCheckpointForTurn($runId, 2), 'append tool_batch should checkpoint two-line state');

        $this->service->restoreForTurn($runId, 1);

        $this->assertSame("LINE_ONE\n", file_get_contents($this->projectDir.'/test.txt'));
    }
}
