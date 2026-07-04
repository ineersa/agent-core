<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\FileRewind;

use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindAfterTurnCommitHook;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindConfig;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindLedgerProjector;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindLedgerStore;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\FileRewindService;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\GitProcessRunner;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\HiddenGitSnapshotBackend;
use Ineersa\CodingAgent\Extension\Builtin\FileRewind\RewindStoragePaths;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitEventSummaryDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookContextDTO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class FileRewindAfterTurnCommitHookTest extends TestCase
{
    private string $projectDir;
    private FileRewindService $service;
    private FileRewindLedgerStore $ledgerStore;

    protected function setUp(): void
    {
        $runner = new GitProcessRunner();
        if (!$runner->isGitAvailable()) {
            $this->markTestSkipped('git not available');
        }
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('file-rewind-hook');
        file_put_contents($this->projectDir.'/marker.txt', "v1\n");
        $paths = new RewindStoragePaths($this->projectDir);
        $this->ledgerStore = new FileRewindLedgerStore($this->projectDir);
        $this->service = new FileRewindService(
            backend: new HiddenGitSnapshotBackend($runner, new NullLogger()),
            gitRunner: $runner,
            paths: $paths,
            ledgerStore: $this->ledgerStore,
            ledgerProjector: new FileRewindLedgerProjector(),
            config: new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1_048_576),
            logger: new NullLogger(),
            projectCwd: $this->projectDir,
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testRecordsCheckpointWhenToolBatchAndTurnEndShareCommit(): void
    {
        $hook = new FileRewindAfterTurnCommitHook(
            $this->service,
            new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1024),
        );

        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-hook',
            turnNo: 2,
            status: 'running',
            events: [
                new AfterTurnCommitEventSummaryDTO(1, 'tool_batch_committed'),
                new AfterTurnCommitEventSummaryDTO(2, 'turn_end'),
            ],
            effectsCount: 0,
        ));

        self::assertTrue($this->service->hasCheckpointForTurn('run-hook', 2));
    }

    public function testRecordsCheckpointOnPlainAssistantTurnEnd(): void
    {
        $hook = new FileRewindAfterTurnCommitHook(
            $this->service,
            new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1024),
        );

        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-hook',
            turnNo: 1,
            status: 'running',
            events: [new AfterTurnCommitEventSummaryDTO(5, 'turn_end')],
            effectsCount: 0,
        ));

        self::assertTrue($this->service->hasCheckpointForTurn('run-hook', 1));
    }


    public function testRecordsCheckpointOnPostToolToolBatchCommitted(): void
    {
        file_put_contents($this->projectDir.'/test.txt', "LINE_ONE
");
        $hook = new FileRewindAfterTurnCommitHook(
            $this->service,
            new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1024),
        );

        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-hook',
            turnNo: 1,
            status: 'running',
            events: [
                new AfterTurnCommitEventSummaryDTO(11, 'tool_batch_committed'),
            ],
            effectsCount: 0,
        ));

        self::assertTrue($this->service->hasCheckpointForTurn('run-hook', 1));
    }

    public function testSkipsMidToolBatchWhenEffectsStillPending(): void
    {
        $hook = new FileRewindAfterTurnCommitHook(
            $this->service,
            new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1024),
        );

        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-hook',
            turnNo: 2,
            status: 'running',
            events: [
                new AfterTurnCommitEventSummaryDTO(1, 'tool_batch_committed'),
            ],
            effectsCount: 1,
        ));

        self::assertFalse($this->service->hasCheckpointForTurn('run-hook', 2));
    }

    public function testRecordsCheckpointOnPostToolFinalAssistantLlmStepCompleted(): void
    {
        file_put_contents($this->projectDir.'/marker.txt', "after-tool
");
        $hook = new FileRewindAfterTurnCommitHook(
            $this->service,
            new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1024),
        );

        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-hook',
            turnNo: 2,
            status: 'running',
            events: [
                new AfterTurnCommitEventSummaryDTO(9, 'llm_step_completed'),
            ],
            effectsCount: 0,
        ));

        self::assertTrue($this->service->hasCheckpointForTurn('run-hook', 2));
    }

    public function testRecordsCheckpointWhenToolBatchAndFinalAssistantShareCommit(): void
    {
        file_put_contents($this->projectDir.'/marker.txt', "one-line
");
        $hook = new FileRewindAfterTurnCommitHook(
            $this->service,
            new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1024),
        );

        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-hook',
            turnNo: 2,
            status: 'running',
            events: [
                new AfterTurnCommitEventSummaryDTO(1, 'tool_batch_committed'),
                new AfterTurnCommitEventSummaryDTO(2, 'llm_step_completed'),
            ],
            effectsCount: 0,
        ));

        self::assertTrue($this->service->hasCheckpointForTurn('run-hook', 2));
    }


    public function testRecordsCheckpointWhenToolBatchSharesCommitWithAgentCommandApplied(): void
    {
        file_put_contents($this->projectDir.'/test.txt', "LINE_ONE\n");
        $hook = new FileRewindAfterTurnCommitHook(
            $this->service,
            new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1024),
        );

        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-hook',
            turnNo: 1,
            status: 'running',
            events: [
                new AfterTurnCommitEventSummaryDTO(11, 'tool_batch_committed'),
                new AfterTurnCommitEventSummaryDTO(12, 'agent_command_applied'),
            ],
            effectsCount: 0,
        ));

        self::assertTrue($this->service->hasCheckpointForTurn('run-hook', 1));
    }

    public function testSkipsWhenDisabled(): void
    {
        $hook = new FileRewindAfterTurnCommitHook(
            $this->service,
            new FileRewindConfig(enabled: false, maxRetainedTurns: 10, maxFileBytes: 1024),
        );

        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-hook',
            turnNo: 1,
            status: 'running',
            events: [new AfterTurnCommitEventSummaryDTO(1, 'turn_end')],
            effectsCount: 0,
        ));

        self::assertFalse($this->service->hasCheckpointForTurn('run-hook', 1));
    }
}
