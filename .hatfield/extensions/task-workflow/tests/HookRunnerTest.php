<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\HatfieldExt\TaskWorkflow\Tests\Support\WorkspaceFixture;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\InvocationControl;
use Ineersa\HatfieldExt\TaskWorkflow\Worktree\HookRunner;
use PHPUnit\Framework\TestCase;

final class HookRunnerTest extends TestCase
{
    public function testContractUsesStableCwdArgumentArrayAndBudget(): void
    {
        $fixture = new WorkspaceFixture();
        try {
            file_put_contents($fixture->root.'/hook.sh', 'exit 0');
            $token = new class implements ToolCancellationTokenInterface {
                public function isCancellationRequested(): bool
                {
                    return false;
                }
            };
            $control = new InvocationControl($token, 10, hrtime(true) + 10_000_000_000);
            $exec = new StubExec(static function (string $command, array $args, ?ExecOptionsDTO $options) use ($fixture, $token): ExecResultDTO {
                self::assertSame('bash', $command);
                self::assertSame(['--noprofile', '--norc', $fixture->root.'/hook.sh'], $args);
                self::assertSame($fixture->root, $options->cwd);
                self::assertSame('/dev/null', $options->env['BASH_ENV']);
                self::assertSame('0', $options->env['HATFIELD_TASK_WORKTREE_EXISTS']);
                self::assertSame($fixture->root, $options->env['HATFIELD_PROJECT_ROOT']);
                self::assertSame($token, $options->cancellationToken);
                self::assertGreaterThan(0, $options->timeout);
                self::assertLessThanOrEqual(3, $options->timeout);

                return new ExecResultDTO(stdout: '', stderr: 'timeout', exitCode: -1, timedOut: true);
            });
            $this->assertTrue((new HookRunner($exec))->run($fixture->root, 'hook.sh', 3, $this->context(), $control)->timedOut);
            $cancelled = new InvocationControl(new class implements ToolCancellationTokenInterface {
                public function isCancellationRequested(): bool
                {
                    return true;
                }
            }, null, null);
            $this->assertTrue((new HookRunner($exec))->run($fixture->root, 'hook.sh', 3, $this->context(), $cancelled)->cancelled);
        } finally {
            $fixture->close();
        }
    }

    public function testLiveBashPreventsInheritedStartupAndHandlesNeverStartedCleanup(): void
    {
        $fixture = new WorkspaceFixture();
        $previous = getenv('BASH_ENV');
        try {
            file_put_contents($fixture->root.'/injection.sh', 'exit 42');
            file_put_contents($fixture->root.'/hook.sh', 'printf "%s:%s:%s" "$HATFIELD_TASK_RESOURCE_ID" "$HATFIELD_TASK_WORKTREE_EXISTS" "$PWD"');
            putenv('BASH_ENV='.$fixture->root.'/injection.sh');
            $runner = new HookRunner($fixture);
            $result = $runner->run($fixture->root, 'hook.sh', 3, $this->context());
            $this->assertSame(0, $result->exitCode);
            $this->assertSame('stable:0:'.$fixture->root, $result->stdout);
            $result = $runner->run($fixture->root, 'hook.sh', 3, $this->context($fixture->directory.'/missing'));
            $this->assertSame(0, $result->exitCode);
        } finally {
            putenv(false === $previous ? 'BASH_ENV' : 'BASH_ENV='.$previous);
            $fixture->close();
        }
    }

    /** @return array<string, string> */
    private function context(string $path = ''): array
    {
        return ['HATFIELD_TASK_REPOSITORY' => 'owner/repo', 'HATFIELD_TASK_NUMBER' => '1', 'HATFIELD_TASK_URL' => 'url', 'HATFIELD_TASK_BRANCH' => 'task/1', 'HATFIELD_TASK_BASE_BRANCH' => 'main', 'HATFIELD_TASK_WORKTREE' => $path, 'HATFIELD_TASK_SETUP_ATTEMPTED' => '0', 'HATFIELD_TASK_REASON' => 'cancel', 'HATFIELD_TASK_RESOURCE_ID' => 'stable', 'HATFIELD_TASK_DATA_DIR' => '/outside/data'];
    }
}
