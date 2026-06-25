<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Exec;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;

final class GitExecutor
{
    public function __construct(
        private readonly ExecInterface $exec,
    ) {
    }

    public function repoRoot(string $cwd): string
    {
        $result = $this->exec->exec('git', ['rev-parse', '--show-toplevel'], new ExecOptionsDTO(cwd: $cwd, timeout: 120.0));
        if (0 !== $result->exitCode) {
            return $cwd;
        }
        $trimmed = trim($result->stdout);

        return '' !== $trimmed ? $trimmed : $cwd;
    }

    /**
     * @param list<string> $args
     */
    public function git(array $args, string $cwd, ?float $timeout = 120.0): \Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO
    {
        return $this->exec->exec('git', $args, new ExecOptionsDTO(cwd: $cwd, timeout: $timeout));
    }

    /**
     * @param list<string> $args
     */
    public function gitOk(array $args, string $cwd, ?float $timeout = 120.0): \Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO
    {
        $result = $this->git($args, $cwd, $timeout);
        if (0 !== $result->exitCode) {
            throw new \RuntimeException('git '.implode(' ', $args)." failed\n".trim('' !== $result->stderr ? $result->stderr : $result->stdout));
        }

        return $result;
    }

    public function branchExists(string $root, string $branch): bool
    {
        $result = $this->git(['show-ref', '--verify', '--quiet', 'refs/heads/'.$branch], $root);

        return 0 === $result->exitCode;
    }
}
