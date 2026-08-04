<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardLock;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\CreateTaskHandler;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CreateTaskHandlerCancellationTest extends TestCase
{
    private string $repoRoot;
    private string $boardRoot;

    protected function setUp(): void
    {
        $this->repoRoot = TestDirectoryIsolation::createProjectTempDir('tw-create-cancel-repo');
        $this->boardRoot = TestDirectoryIsolation::createProjectTempDir('tw-create-cancel-board');
        foreach (TaskStatusEnum::all() as $status) {
            mkdir($this->boardRoot.'/'.$status->value, 0o755, true);
        }
        putenv('HATFIELD_TASK_WORKFLOW_ROOT='.$this->boardRoot);
    }

    protected function tearDown(): void
    {
        putenv('HATFIELD_TASK_WORKFLOW_ROOT');
        TestDirectoryIsolation::removeDirectory($this->boardRoot);
        TestDirectoryIsolation::removeDirectory($this->repoRoot);
    }

    #[Test]
    public function cancellationWhileWaitingForLockDoesNotCreateTaskFile(): void
    {
        // Thesis: create_task must return structured cancelled and never write the task file
        // when Escape cancels during board-lock wait.
        $lockPath = TaskBoardLock::lockPathForRoot($this->boardRoot);
        $holder = fopen($lockPath, 'c+b');
        $this->assertNotFalse($holder);
        $this->assertTrue(flock($holder, \LOCK_EX | \LOCK_NB));

        try {
            $token = new class implements ToolCancellationTokenInterface {
                private int $checks = 0;

                public function isCancellationRequested(): bool
                {
                    return ++$this->checks >= 2;
                }
            };

            $handler = new CreateTaskHandler(new TaskBoardStore(
                $this->repoRoot,
                new TaskWorkflowSettings(taskRoot: $this->boardRoot),
            ));

            $result = ($handler)(
                ['title' => 'Should not create', 'id' => '2026-01-01-should-not-create'],
                new ToolInvocationContextDTO(runId: 'run-create-cancel', cancellationToken: $token),
            );

            $this->assertIsArray($result);
            $this->assertTrue($result['cancelled'] ?? false);
            $this->assertFileDoesNotExist($this->boardRoot.'/TODO/2026-01-01-should-not-create.md');
        } finally {
            flock($holder, \LOCK_UN);
            fclose($holder);
        }
    }

    #[Test]
    public function deadlineWhileWaitingForLockClosesOwnedHandleAndDoesNotCreateTask(): void
    {
        // Thesis: deadline path must return timed_out, release its lock handle, and skip file create.
        $lockPath = TaskBoardLock::lockPathForRoot($this->boardRoot);
        $holder = fopen($lockPath, 'c+b');
        $this->assertNotFalse($holder);
        $this->assertTrue(flock($holder, \LOCK_EX | \LOCK_NB));

        try {
            $handler = new CreateTaskHandler(new TaskBoardStore(
                $this->repoRoot,
                new TaskWorkflowSettings(taskRoot: $this->boardRoot),
            ));

            $started = hrtime(true);
            $result = ($handler)(
                ['title' => 'Should timeout', 'id' => '2026-01-01-should-timeout'],
                new ToolInvocationContextDTO(runId: 'run-create-timeout', timeoutSeconds: 1),
            );
            $elapsedMs = (hrtime(true) - $started) / 1_000_000;

            $this->assertIsArray($result);
            $this->assertTrue($result['timed_out'] ?? false);
            $this->assertSame(1, $result['timeout_seconds'] ?? null);
            $this->assertFileDoesNotExist($this->boardRoot.'/TODO/2026-01-01-should-timeout.md');
            $this->assertLessThan(2500, $elapsedMs);

            // Handler must have closed its owned handle so a later acquire succeeds immediately.
            $probe = fopen($lockPath, 'c+b');
            $this->assertNotFalse($probe);
            // Still held by $holder, so nonblocking acquire fails — proves only one exclusive owner.
            $this->assertFalse(flock($probe, \LOCK_EX | \LOCK_NB));
            fclose($probe);
        } finally {
            flock($holder, \LOCK_UN);
            fclose($holder);
        }

        // After holder release, a fresh withLock must succeed (no leaked handler lock).
        $lock = new TaskBoardLock($lockPath);
        $ok = $lock->withLock(static fn (): string => 'ok');
        $this->assertSame('ok', $ok);
    }
}
