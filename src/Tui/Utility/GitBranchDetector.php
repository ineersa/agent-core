<?php

declare(strict_types=1);

namespace Ineersa\Tui\Utility;

use Symfony\Component\Process\Exception\ExceptionInterface;
use Symfony\Component\Process\Process;

/**
 * Detects the current git branch name for footer display.
 *
 * Runs `git rev-parse --abbrev-ref HEAD` in the current working
 * directory via Symfony Process (replaces a raw proc_open helper).
 */
final class GitBranchDetector
{
    public function detect(): string
    {
        try {
            $process = new Process(['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
            $process->run();
        } catch (ExceptionInterface $e) {
            // Intentional local degradation: git is not installed or could
            // not start; the footer simply shows no branch. Nothing to
            // rethrow or log — this is a display-only best-effort lookup.
            return '';
        }

        if (!$process->isSuccessful()) {
            // Non-repo directory or other git failure — same degradation.
            return '';
        }

        $branch = trim($process->getOutput());

        return '' !== $branch ? $branch : '';
    }
}
