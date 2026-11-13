<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests\Support;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Exec\GitExecutor;
use Symfony\Component\Process\Process;

final class WorkspaceFixture implements ExecInterface
{
    public readonly string $directory;
    public readonly string $root;
    public readonly GitExecutor $git;

    public function __construct()
    {
        $this->directory = TestDirectoryIsolation::createProjectTempDir('workspace');
        $this->root = $this->directory.'/primary';
        mkdir($this->root);
        $this->git = new GitExecutor($this);
        $this->git->gitOk(['init', '-b', 'main'], $this->root);
        $this->git->gitOk(['config', 'user.email', 'test@example.invalid'], $this->root);
        $this->git->gitOk(['config', 'user.name', 'Test'], $this->root);
        $this->git->gitOk(['commit', '--allow-empty', '-m', 'initial'], $this->root);
    }

    public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO
    {
        $process = new Process([$command, ...$args], $options?->cwd, $options?->env ?? [], timeout: $options?->timeout ?? 10.0);
        $process->run();

        return new ExecResultDTO(stdout: $process->getOutput(), stderr: $process->getErrorOutput(), exitCode: $process->getExitCode() ?? -1);
    }

    public function close(): void
    {
        TestDirectoryIsolation::removeDirectory($this->directory);
    }
}
