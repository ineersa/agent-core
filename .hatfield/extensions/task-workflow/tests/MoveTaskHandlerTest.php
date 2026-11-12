<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\TransitionFixture;
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

        $this->move('DONE');
        $this->assertSame('DONE', $this->fixture->status);
        $this->assertSame('MERGED', $this->fixture->pr['state']);
        $this->assertDirectoryDoesNotExist($path);
        $this->assertNull($this->fixture->record());
        $this->assertSame(['setup', 'validate', 'cleanup'], array_column($this->fixture->hookCalls, 0));
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
        $this->fixture->hookResults['validate'] = new ExecResultDTO('', 'validation failed', 1);
        $this->move('CODE-REVIEW');
        $this->assertSame('IN-PROGRESS', $this->fixture->status);
        $this->assertNull($this->fixture->pr);
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
