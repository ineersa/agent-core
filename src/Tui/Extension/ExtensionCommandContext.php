<?php

declare(strict_types=1);

namespace Ineersa\Tui\Extension;

use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;

/**
 * Mutable message collector for extension slash command execution.
 *
 * Collects notify() messages and tracks the highest severity level
 * to allow callers to choose the appropriate TUI transcript rendering.
 */
final class ExtensionCommandContext implements CommandContextInterface
{
    /** @var list<string> */
    public array $messages = [];

    /** @var int highest-severity level seen: 0=info, 1=success, 2=warning, 3=error */
    public int $highestSeverity = 0;

    public function notify(string $message, string $level = 'info'): void
    {
        $this->messages[] = $message;
        $sev = match ($level) {
            'error' => 3,
            'warning' => 2,
            'success' => 1,
            default => 0,
        };
        if ($sev > $this->highestSeverity) {
            $this->highestSeverity = $sev;
        }
    }
}
