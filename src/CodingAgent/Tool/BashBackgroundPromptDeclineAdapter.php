<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool;

/**
 * Default non-interactive background prompt adapter that always declines.
 *
 * Production default for TOOLS-09. Always returns false, meaning
 * the bash tool continues supervising the command until completion,
 * timeout, or cancellation.
 *
 * Replaced by a runtime/TUI bridge implementation in TOOLS-09B.
 */
final class BashBackgroundPromptDeclineAdapter implements BashBackgroundPromptAdapterInterface
{
    public function shouldBackground(string $command, int $pid, string $logPath, float $elapsedSeconds): bool
    {
        return false;
    }
}
