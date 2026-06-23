<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Pr;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;

final class PrManager
{
    public function __construct(
        private readonly ExecInterface $exec,
    ) {
    }

    public function pushTaskBranch(string $root, string $branch): string
    {
        $remoteResult = $this->exec->exec('git', ['remote', 'get-url', 'origin'], new ExecOptionsDTO(cwd: $root, timeout: 120.0));
        if (0 !== $remoteResult->exitCode) {
            throw new \RuntimeException("No git remote 'origin' configured. Push requires a remote repository.\n\nSet one with:\n  git remote add origin <url>");
        }

        $pushResult = $this->exec->exec('git', ['push', '-u', 'origin', $branch], new ExecOptionsDTO(cwd: $root, timeout: 120.0));
        if (0 !== $pushResult->exitCode) {
            throw new \RuntimeException('git push failed:'.\PHP_EOL.trim('' !== $pushResult->stderr ? $pushResult->stderr : $pushResult->stdout));
        }

        $out = trim('' !== $pushResult->stdout ? $pushResult->stdout : $pushResult->stderr);

        return '' !== $out ? $out : 'Pushed '.$branch.' to origin.';
    }

    /**
     * @return array{available: bool, reason?: string}
     */
    public function ghAvailable(string $root): array
    {
        $authResult = $this->exec->exec('gh', ['auth', 'status'], new ExecOptionsDTO(cwd: $root, timeout: 120.0));
        if (0 === $authResult->exitCode) {
            return ['available' => true];
        }
        $err = '' !== $authResult->stderr ? $authResult->stderr : $authResult->stdout;
        if (str_contains($err, 'not found') || 127 === $authResult->exitCode) {
            return ['available' => false, 'reason' => 'GitHub CLI (gh) is not installed. Install it from https://cli.github.com/'];
        }

        return ['available' => false, 'reason' => 'gh is not authenticated: '.trim($err)];
    }

    public function findExistingPr(string $root, string $branch): ?string
    {
        $result = $this->exec->exec(
            'gh',
            ['pr', 'list', '--head', $branch, '--json', 'url', '--jq', '.[0].url', '--state', 'open'],
            new ExecOptionsDTO(cwd: $root, timeout: 120.0)
        );
        if (0 !== $result->exitCode) {
            return null;
        }
        $url = trim($result->stdout);

        return '' !== $url ? $url : null;
    }

    public function createPr(string $root, string $branch, string $title, string $body, ?string $baseBranch = null): string
    {
        $args = ['pr', 'create', '--head', $branch, '--title', $title];
        if ('' !== trim($body)) {
            $args[] = '--body';
            $args[] = $body;
        }
        if (null !== $baseBranch && '' !== $baseBranch) {
            $args[] = '--base';
            $args[] = $baseBranch;
        }

        $result = $this->exec->exec('gh', $args, new ExecOptionsDTO(cwd: $root, timeout: 120.0));
        if (0 !== $result->exitCode) {
            $err = '' !== $result->stderr ? $result->stderr : $result->stdout;
            if (str_contains($err, 'already exists') || str_contains($err, 'pull request already exists')) {
                $existing = $this->findExistingPr($root, $branch);
                if (null !== $existing) {
                    return $existing;
                }
            }
            throw new \RuntimeException('gh pr create failed:'.\PHP_EOL.trim($err));
        }
        $url = trim($result->stdout);
        if ('' === $url) {
            throw new \RuntimeException('gh pr create succeeded but produced no output URL.');
        }

        return $url;
    }
}
