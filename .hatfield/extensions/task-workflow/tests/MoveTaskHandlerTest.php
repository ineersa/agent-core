<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exec\GitExecutor;
use Ineersa\HatfieldExt\TaskWorkflow\Pr\PrManager;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskBoardStore;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskMarkdown;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\MoveTaskHandler;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorktreeManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MoveTaskHandlerTest extends TestCase
{
    private string $repoRoot;
    private string $boardRoot;
    private string $worktreesBase;

    protected function setUp(): void
    {
        $this->repoRoot = TestDirectoryIsolation::createProjectTempDir('tw-git');
        $this->initGitRepo($this->repoRoot);
        $this->boardRoot = TestDirectoryIsolation::createProjectTempDir('tw-board');
        foreach (TaskStatusEnum::all() as $s) {
            mkdir($this->boardRoot.'/'.$s->value, 0o755, true);
        }
        $this->worktreesBase = \dirname($this->repoRoot).'/'.basename($this->repoRoot).'-worktrees';
        putenv('HATFIELD_TASK_WORKFLOW_ROOT='.$this->boardRoot);
    }

    protected function tearDown(): void
    {
        putenv('HATFIELD_TASK_WORKFLOW_ROOT');
        TestDirectoryIsolation::removeDirectory($this->boardRoot);
        TestDirectoryIsolation::removeDirectory($this->repoRoot);
    }

    #[Test]
    public function happyPathTodoInProgressDone(): void
    {
        $exec = new StubExec($this->gitStub(...));
        $git = new GitExecutor($exec);
        $store = new TaskBoardStore($this->repoRoot, new TaskWorkflowSettings(taskRoot: $this->boardRoot));
        $handler = new MoveTaskHandler(
            $store,
            $git,
            new WorktreeManager($git),
            new PrManager($exec),
            $exec,
            new TaskWorkflowSettings(taskRoot: $this->boardRoot),
            $this->repoRoot,
        );

        $slug = '2026-01-01-test-task';
        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('Test task'));

        $r1 = ($handler)(['task' => $slug, 'to' => 'IN-PROGRESS', 'worktreeBase' => $this->worktreesBase]);
        $this->assertStringContainsString('Moved task', $r1['content'][0]['text']);
        $branch = 'task/'.$slug;
        $this->assertTrue($this->branchExists($branch));
        $this->assertDirectoryExists($this->worktreesBase.'/'.$slug);

        ($handler)(['task' => $slug, 'from' => 'IN-PROGRESS', 'to' => 'DONE', 'cleanupWorktree' => true]);
        $this->assertFileExists($this->boardRoot.'/DONE/'.$slug.'.md');
    }

    #[Test]
    public function refusesDirtyIntegrationCheckout(): void
    {
        file_put_contents($this->repoRoot.'/dirty.txt', 'x');
        $exec = new StubExec($this->gitStub(...));
        $git = new GitExecutor($exec);
        $store = new TaskBoardStore($this->repoRoot, new TaskWorkflowSettings(taskRoot: $this->boardRoot));
        $slug = 'dirty-claim';
        file_put_contents($this->boardRoot.'/TODO/'.$slug.'.md', TaskMarkdown::renderTask('Dirty'));

        $handler = new MoveTaskHandler($store, $git, new WorktreeManager($git), new PrManager($exec), $exec, new TaskWorkflowSettings(), $this->repoRoot);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Integration checkout is not clean');
        ($handler)(['task' => $slug, 'to' => 'IN-PROGRESS', 'worktreeBase' => $this->worktreesBase]);
    }

    public function gitStub(string $command, array $args, ?ExecOptionsDTO $options): ExecResultDTO
    {
        $cwd = $options?->cwd ?? $this->repoRoot;
        if ('timeout' === $command) {
            return new ExecResultDTO('', '', 0);
        }
        if ('git' === $command) {
            $full = 'git '.implode(' ', array_map('escapeshellarg', $args));
            $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($full, $descriptor, $pipes, $cwd);
            if (!\is_resource($proc)) {
                return new ExecResultDTO('', 'proc failed', 1);
            }
            $stdout = stream_get_contents($pipes[1]) ?: '';
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);

            return new ExecResultDTO($stdout, $stderr, $code);
        }
        if ('gh' === $command) {
            return new ExecResultDTO('https://github.com/example/pr/1', '', 0);
        }

        return new ExecResultDTO('', '', 0);
    }

    private function initGitRepo(string $root): void
    {
        $this->runGit($root, ['init', '-b', 'main']);
        $this->runGit($root, ['config', 'user.email', 'test@example.com']);
        $this->runGit($root, ['config', 'user.name', 'Test']);
        $this->runGit($root, ['config', 'commit.gpgsign', 'false']);
        file_put_contents($root.'/README', 'init');
        $this->runGit($root, ['add', 'README']);
        $this->runGit($root, ['commit', '-m', 'init']);
    }

    /**
     * @param list<string> $args
     */
    private function runGit(string $cwd, array $args): void
    {
        $cmd = 'git '.implode(' ', array_map('escapeshellarg', $args));
        $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $descriptor, $pipes, $cwd);
        if (!\is_resource($proc)) {
            throw new \RuntimeException('proc_open failed');
        }
        stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        if (0 !== $code) {
            throw new \RuntimeException('git failed: '.$cmd.' code='.$code);
        }
    }

    private function branchExists(string $branch): bool
    {
        $proc = proc_open('git show-ref --verify --quiet refs/heads/'.$branch, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->repoRoot);
        if (!\is_resource($proc)) {
            return false;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);

        return 0 === proc_close($proc);
    }
}
