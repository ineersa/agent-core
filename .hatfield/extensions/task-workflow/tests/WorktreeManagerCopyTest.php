<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolInvocationContextDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exec\GitExecutor;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskInfo;
use Ineersa\HatfieldExt\TaskWorkflow\Store\TaskStatusEnum;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\InvocationControl;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorktreeCreateResult;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\WorktreeManager;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class WorktreeManagerCopyTest extends TestCase
{
    private string $repoRoot;
    private string $worktreesBase;

    protected function setUp(): void
    {
        $this->repoRoot = TestDirectoryIsolation::createProjectTempDir('tw-copy-git');
        $this->initGitRepo($this->repoRoot);
        $extDir = $this->repoRoot.'/.hatfield/extensions';
        mkdir($extDir, 0o755, true);
        file_put_contents($extDir.'/composer.json', '{"name": "test/extensions"}');
        $this->runGit($this->repoRoot, ['add', '.hatfield/']);
        $this->runGit($this->repoRoot, ['commit', '-m', 'add extensions']);
        $this->worktreesBase = \dirname($this->repoRoot).'/'.basename($this->repoRoot).'-worktrees';
    }

    protected function tearDown(): void
    {
        if (isset($this->worktreesBase) && is_dir($this->worktreesBase)) {
            TestDirectoryIsolation::removeDirectory($this->worktreesBase);
        }
        TestDirectoryIsolation::removeDirectory($this->repoRoot);
    }

    #[Test]
    public function nativeCpCopiesVendorAndVeraTrees(): void
    {
        $this->seedTree($this->repoRoot.'/vendor', [
            'autoload.php' => "<?php\n",
            'pkg/A.php' => "<?php class A {}\n",
        ]);
        $this->seedTree($this->repoRoot.'/.vera', [
            'index.json' => "{\"ok\":true}\n",
            'nested/b.txt' => "b\n",
        ]);

        $recording = new RecordingExec(new StubExec($this->execStub(...)));
        $git = new GitExecutor($recording);
        $manager = new WorktreeManager($git, $recording);

        $slug = '2026-01-01-native-copy';
        $result = $manager->createWorktreeForTask(
            $this->repoRoot,
            new TaskInfo(
                status: TaskStatusEnum::TODO,
                file: $slug.'.md',
                path: '/tmp/'.$slug.'.md',
                title: 'Native copy',
            ),
            $this->worktreesBase,
            InvocationControl::fromContext($this->ctx()),
        );

        $this->assertInstanceOf(WorktreeCreateResult::class, $result);
        $this->assertTrue($result->vendorCopied);
        $this->assertTrue($result->veraCopied);

        $worktree = $this->worktreesBase.'/'.$slug;
        $this->assertFileEquals($this->repoRoot.'/vendor/autoload.php', $worktree.'/vendor/autoload.php');
        $this->assertFileEquals($this->repoRoot.'/vendor/pkg/A.php', $worktree.'/vendor/pkg/A.php');
        $this->assertFileEquals($this->repoRoot.'/.vera/index.json', $worktree.'/.vera/index.json');
        $this->assertFileEquals($this->repoRoot.'/.vera/nested/b.txt', $worktree.'/.vera/nested/b.txt');

        $cpCalls = array_values(array_filter(
            $recording->calls(),
            static fn (array $c): bool => 'cp' === $c['command'],
        ));
        $this->assertCount(2, $cpCalls);
        $this->assertSame(['-a', $this->repoRoot.'/vendor/.', $worktree.'/vendor/'], $cpCalls[0]['args']);
        $this->assertSame(['-a', $this->repoRoot.'/.vera/.', $worktree.'/.vera/'], $cpCalls[1]['args']);
    }

    #[Test]
    public function cancelledNativeVendorCopyCleansPartialWorktree(): void
    {
        $this->seedTree($this->repoRoot.'/vendor', [
            'autoload.php' => "<?php\n",
        ]);

        $inner = new StubExec(function (string $command, array $args, ?ExecOptionsDTO $options): ExecResultDTO {
            if ('cp' === $command) {
                return new ExecResultDTO('', 'Interrupted while copying vendor.', -1, cancelled: true);
            }

            return $this->execStub($command, $args, $options);
        });
        $recording = new RecordingExec($inner);
        $git = new GitExecutor($recording);
        $manager = new WorktreeManager($git, $recording);

        $slug = '2026-01-01-copy-cancel';
        $worktree = $this->worktreesBase.'/'.$slug;
        $result = $manager->createWorktreeForTask(
            $this->repoRoot,
            new TaskInfo(
                status: TaskStatusEnum::TODO,
                file: $slug.'.md',
                path: '/tmp/'.$slug.'.md',
                title: 'Copy cancel',
            ),
            $this->worktreesBase,
            InvocationControl::fromContext($this->ctx()),
        );

        $this->assertInstanceOf(ExecResultDTO::class, $result);
        $this->assertTrue($result->cancelled);
        $this->assertDirectoryDoesNotExist($worktree);

        $cpCalls = array_values(array_filter(
            $recording->calls(),
            static fn (array $c): bool => 'cp' === $c['command'],
        ));
        $this->assertCount(1, $cpCalls);
        $this->assertSame(['-a', $this->repoRoot.'/vendor/.', $worktree.'/vendor/'], $cpCalls[0]['args']);
    }

    /**
     * @param array<string, string> $files
     */
    private function seedTree(string $root, array $files): void
    {
        foreach ($files as $rel => $contents) {
            $path = $root.'/'.$rel;
            $dir = \dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0o755, true);
            }
            file_put_contents($path, $contents);
        }
    }

    private function execStub(string $command, array $args, ?ExecOptionsDTO $options): ExecResultDTO
    {
        $cwd = $options?->cwd ?? $this->repoRoot;
        if ('cp' === $command || 'git' === $command || 'composer' === $command) {
            $full = $command.' '.implode(' ', array_map('escapeshellarg', $args));
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

        return new ExecResultDTO('', '', 0);
    }

    private function ctx(?ToolCancellationTokenInterface $cancellationToken = null): ToolInvocationContextDTO
    {
        return new ToolInvocationContextDTO(
            runId: 'run-copy-test',
            cancellationToken: $cancellationToken,
        );
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
}
