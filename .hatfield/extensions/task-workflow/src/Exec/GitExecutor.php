<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Exec;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\TaskWorkflow\Tool\InvocationControl;

final class GitExecutor
{
    public function __construct(
        private readonly ExecInterface $exec,
    ) {
    }

    /**
     * @param list<string> $args
     */
    public function git(
        array $args,
        string $cwd,
        ?float $timeout = 120.0,
        ?InvocationControl $control = null,
    ): ExecResultDTO {
        return $this->exec->exec(
            'git',
            $args,
            new ExecOptionsDTO(
                cwd: $cwd,
                timeout: $control?->remainingTimeoutSeconds($timeout) ?? $timeout,
                cancellationToken: $control?->cancellationToken,
            ),
        );
    }

    /**
     * @param list<string> $args
     */
    public function gitOk(
        array $args,
        string $cwd,
        ?float $timeout = 120.0,
        ?InvocationControl $control = null,
    ): ExecResultDTO {
        $result = $this->git($args, $cwd, $timeout, $control);
        if ($result->cancelled || $result->timedOut) {
            return $result;
        }
        if (0 !== $result->exitCode) {
            throw new \RuntimeException('git '.implode(' ', $args)." failed\n".trim('' !== $result->stderr ? $result->stderr : $result->stdout));
        }

        return $result;
    }

    public function branchExists(string $root, string $branch, ?InvocationControl $control = null): bool
    {
        $result = $this->git(['show-ref', '--verify', '--quiet', 'refs/heads/'.$branch], $root, 120.0, $control);
        if ($result->cancelled || $result->timedOut) {
            return false;
        }

        return 0 === $result->exitCode;
    }
}
