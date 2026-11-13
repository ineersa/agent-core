<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\TransitionFixture;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorkspaceStateStore;
use PHPUnit\Framework\TestCase;

final class MoveTaskHandlerTest extends TestCase
{
    private TransitionFixture $fixture;

    protected function setUp(): void
    {
        $this->fixture = new TransitionFixture();
    }

    protected function tearDown(): void
    {
        $this->fixture->workspace->close();
    }

    public function testTaskStartReviewAndDone(): void
    {
        $this->move('IN-PROGRESS');
        $path = $this->fixture->record()['worktree'];
        $this->assertSame('IN-PROGRESS', $this->fixture->status);
        $this->assertDirectoryExists($path);

        file_put_contents($path.'/change.txt', 'Implementation');
        $git = $this->fixture->workspace->git;
        $git->gitOk(['add', 'change.txt'], $path);
        $git->gitOk(['commit', '-m', 'Implement task'], $path);
        $this->move('CODE-REVIEW');
        $this->assertSame('CODE-REVIEW', $this->fixture->status);
        $this->assertSame('OPEN', $this->fixture->pr['state']);

        $linkedStore = new WorkspaceStateStore($git, $path);
        $contender = null;
        $order = [];
        $this->fixture->onHook = function (string $phase, ExecOptionsDTO $options) use ($linkedStore, &$contender, &$order): void {
            if ('validate' !== $phase || 'done' !== $options->env['HATFIELD_TASK_REASON']) {
                return;
            }
            $entered = new DeferredFuture();
            $contender = \Amp\async(static function () use ($linkedStore, $entered, &$order): void {
                $entered->complete();
                $linkedStore->primaryLock()->withLock(static function () use (&$order): void {
                    $order[] = 'other-transition';
                });
            });
            $entered->getFuture()->await(new TimeoutCancellation(2));
            $this->assertFalse($contender->isComplete());
            $order[] = 'validation';
        };
        try {
            $this->move('DONE');
        } finally {
            $contender?->await(new TimeoutCancellation(2));
        }
        $this->assertSame(['validation', 'other-transition'], $order);
        $this->assertSame('DONE', $this->fixture->status);
        $this->assertSame('MERGED', $this->fixture->pr['state']);
        $this->assertDirectoryDoesNotExist($path);
        $this->assertNull($this->fixture->record());
        $this->assertSame(['setup', 'validate', 'cleanup', 'validate'], array_column($this->fixture->hookCalls, 0));
        $postMerge = $this->fixture->hookCalls[3];
        $this->assertSame('done', $postMerge[1]['HATFIELD_TASK_REASON']);
        $this->assertSame('0', $postMerge[1]['HATFIELD_TASK_WORKTREE_EXISTS']);
        $this->assertSame($this->fixture->workspace->root, $postMerge[2]);
    }

    public function testTodoCancellationRunsCleanupWithoutWorkspace(): void
    {
        $this->move('CANCELLED');
        $this->assertSame('CANCELLED', $this->fixture->status);
        $this->assertSame(['cleanup'], array_column($this->fixture->hookCalls, 0));
        $this->assertSame('', $this->fixture->hookCalls[0][1]['HATFIELD_TASK_WORKTREE']);
        $this->assertNull($this->fixture->record());
    }

    public function testFailedValidationDoesNotSubmitReview(): void
    {
        $this->move('IN-PROGRESS');
        $this->fixture->hookResults['validate'] = new ExecResultDTO(str_repeat('progress ', 1000).'report-path', 'validation failed', 1);
        $result = $this->move('CODE-REVIEW');
        $this->assertStringContainsString('validation failed', $result);
        $this->assertStringContainsString('report-path', $result);
        $this->assertSame('IN-PROGRESS', $this->fixture->status);
        $this->assertNull($this->fixture->pr);
    }

    public function testFailedPostMergeValidationKeepsPendingWorkflow(): void
    {
        $this->move('IN-PROGRESS');
        $this->move('CODE-REVIEW');
        $path = $this->fixture->record()['worktree'];
        $this->fixture->hookResults['validate'] = new ExecResultDTO('', 'integrated check failed', 1);

        $result = $this->move('DONE');

        $this->assertStringContainsString('integrated check failed', $result);
        $this->assertSame('MERGED', $this->fixture->pr['state']);
        $this->assertDirectoryDoesNotExist($path);
        $this->assertSame('CODE-REVIEW', $this->fixture->status);
        $this->assertSame('done', $this->fixture->record()['pending_operation']);
    }

    public function testDirtyWorkspaceCannotBeCancelled(): void
    {
        $this->move('IN-PROGRESS');
        $path = $this->fixture->record()['worktree'];
        file_put_contents($path.'/valuable', 'keep');
        $this->refused(fn () => $this->move('CANCELLED'));
        $this->assertFileExists($path.'/valuable');
        $this->assertSame('IN-PROGRESS', $this->fixture->status);
    }

    public function testReplacementRepositoryCannotRunReviewHook(): void
    {
        $this->move('IN-PROGRESS');
        $path = $this->fixture->record()['worktree'];
        TestDirectoryIsolation::removeDirectory($path);
        $this->fixture->workspace->git->gitOk(['init', '--initial-branch=task/issue-7', $path], $this->fixture->workspace->root);

        $this->refused(fn () => $this->move('CODE-REVIEW'));

        $this->assertSame(['setup'], array_column($this->fixture->hookCalls, 0));
        $this->assertSame('IN-PROGRESS', $this->fixture->status);
    }

    public function testUnexpectedCommittedHeadCannotBeCompleted(): void
    {
        $this->move('IN-PROGRESS');
        $this->move('CODE-REVIEW');
        $path = $this->fixture->record()['worktree'];
        $this->fixture->workspace->git->gitOk(['commit', '--allow-empty', '-m', 'Unsubmitted work'], $path);
        $this->refused(fn () => $this->move('DONE'));
        $this->assertDirectoryExists($path);
        $this->assertSame('CODE-REVIEW', $this->fixture->status);
    }

    public function testCleanupFailureKeepsWorkspaceAndWorkflow(): void
    {
        $this->move('IN-PROGRESS');
        $path = $this->fixture->record()['worktree'];
        $this->fixture->hookResults['cleanup'] = new ExecResultDTO('', 'cleanup failed', 1);
        $this->move('CANCELLED');
        $this->assertSame('IN-PROGRESS', $this->fixture->status);
        $this->assertDirectoryExists($path);
    }

    private function move(string $to, array $extra = []): mixed
    {
        return ($this->fixture->handler)(['task' => '7', 'to' => $to, ...$extra], new ToolInvocationContextDTO('test'));
    }

    private function refused(callable $action): void
    {
        try {
            $action();
            $this->fail('Expected transition refusal');
        } catch (\RuntimeException $exception) {
            $this->assertNotSame('', $exception->getMessage());
        }
    }
}
