<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\FileRewind\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterTurnCommitHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Session\SessionEventDTO;
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
                new SessionEventDTO(
                    runId: 'run-test',
                    seq: 1,
                    turnNo: 1,
                    type: 'tool_batch_committed',
                    payload: [],
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
                new SessionEventDTO(
                    runId: 'run-test',
                    seq: 2,
                    turnNo: 1,
                    type: 'turn_end',
                    payload: [],
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
            ],
            effectsCount: 0,
        ));

        $this->assertTrue($this->service->hasCheckpointForTurn('run-hook', 2));
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
            events: [new SessionEventDTO(
                runId: 'run-test',
                seq: 5,
                turnNo: 1,
                type: 'turn_end',
                payload: [],
                createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            )],
            effectsCount: 0,
        ));

        $this->assertTrue($this->service->hasCheckpointForTurn('run-hook', 1));
    }

    public function testRecordsCheckpointOnPostToolToolBatchCommitted(): void
    {
        file_put_contents($this->projectDir.'/test.txt', 'LINE_ONE
');
        $hook = new FileRewindAfterTurnCommitHook(
            $this->service,
            new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1024),
        );

        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-hook',
            turnNo: 1,
            status: 'running',
            events: [
                new SessionEventDTO(
                    runId: 'run-test',
                    seq: 11,
                    turnNo: 1,
                    type: 'tool_batch_committed',
                    payload: [],
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
            ],
            effectsCount: 0,
        ));

        $this->assertTrue($this->service->hasCheckpointForTurn('run-hook', 1));
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
                new SessionEventDTO(
                    runId: 'run-test',
                    seq: 1,
                    turnNo: 1,
                    type: 'tool_batch_committed',
                    payload: [],
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
            ],
            effectsCount: 1,
        ));

        $this->assertFalse($this->service->hasCheckpointForTurn('run-hook', 2));
    }

    public function testRecordsCheckpointOnPostToolFinalAssistantLlmStepCompleted(): void
    {
        file_put_contents($this->projectDir.'/marker.txt', 'after-tool
');
        $hook = new FileRewindAfterTurnCommitHook(
            $this->service,
            new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1024),
        );

        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-hook',
            turnNo: 2,
            status: 'running',
            events: [
                new SessionEventDTO(
                    runId: 'run-test',
                    seq: 9,
                    turnNo: 1,
                    type: 'llm_step_completed',
                    payload: [],
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
            ],
            effectsCount: 0,
        ));

        $this->assertTrue($this->service->hasCheckpointForTurn('run-hook', 2));
    }

    public function testRecordsCheckpointWhenToolBatchAndFinalAssistantShareCommit(): void
    {
        file_put_contents($this->projectDir.'/marker.txt', 'one-line
');
        $hook = new FileRewindAfterTurnCommitHook(
            $this->service,
            new FileRewindConfig(enabled: true, maxRetainedTurns: 10, maxFileBytes: 1024),
        );

        $hook->onAfterTurnCommit(new AfterTurnCommitHookContextDTO(
            runId: 'run-hook',
            turnNo: 2,
            status: 'running',
            events: [
                new SessionEventDTO(
                    runId: 'run-test',
                    seq: 1,
                    turnNo: 1,
                    type: 'tool_batch_committed',
                    payload: [],
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
                new SessionEventDTO(
                    runId: 'run-test',
                    seq: 2,
                    turnNo: 1,
                    type: 'llm_step_completed',
                    payload: [],
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
            ],
            effectsCount: 0,
        ));

        $this->assertTrue($this->service->hasCheckpointForTurn('run-hook', 2));
    }

    public function testRecordsCheckpointOnToolBatchCommitWithToolResultEvents(): void
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
                new SessionEventDTO(
                    runId: 'run-test',
                    seq: 7,
                    turnNo: 1,
                    type: 'tool_call_result_received',
                    payload: [],
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
                new SessionEventDTO(
                    runId: 'run-test',
                    seq: 8,
                    turnNo: 1,
                    type: 'tool_execution_end',
                    payload: [],
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
                new SessionEventDTO(
                    runId: 'run-test',
                    seq: 10,
                    turnNo: 1,
                    type: 'message_end',
                    payload: [],
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
                new SessionEventDTO(
                    runId: 'run-test',
                    seq: 11,
                    turnNo: 1,
                    type: 'tool_batch_committed',
                    payload: [],
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
                new SessionEventDTO(
                    runId: 'run-test',
                    seq: 12,
                    turnNo: 1,
                    type: 'agent_command_applied',
                    payload: [],
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
            ],
            effectsCount: 0,
        ));

        $this->assertTrue($this->service->hasCheckpointForTurn('run-hook', 1));
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
                new SessionEventDTO(
                    runId: 'run-test',
                    seq: 11,
                    turnNo: 1,
                    type: 'tool_batch_committed',
                    payload: [],
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
                new SessionEventDTO(
                    runId: 'run-test',
                    seq: 12,
                    turnNo: 1,
                    type: 'agent_command_applied',
                    payload: [],
                    createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                ),
            ],
            effectsCount: 0,
        ));

        $this->assertTrue($this->service->hasCheckpointForTurn('run-hook', 1));
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
            events: [new SessionEventDTO(
                runId: 'run-test',
                seq: 1,
                turnNo: 1,
                type: 'turn_end',
                payload: [],
                createdAt: new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            )],
            effectsCount: 0,
        ));

        $this->assertFalse($this->service->hasCheckpointForTurn('run-hook', 1));
    }
}
