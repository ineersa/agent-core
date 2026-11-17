<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Claude;

use Ineersa\Hatfield\ExtensionApi\Command\CommandContextInterface;

/**
 * Collects command notifications so a Hatfield slash command can run as an
 * MCP tool and return what it would have shown the user.
 */
final class CapturingCommandContext implements CommandContextInterface
{
    /** @var list<array{message: string, level: string}> */
    private array $notifications = [];

    public function notify(string $message, string $level = 'info'): void
    {
        $this->notifications[] = ['message' => $message, 'level' => $level];
    }

    public function text(): string
    {
        return implode("\n\n", array_map(static fn (array $notification): string => $notification['message'], $this->notifications));
    }

    public function hasError(): bool
    {
        foreach ($this->notifications as $notification) {
            if ('error' === $notification['level']) {
                return true;
            }
        }

        return false;
    }
}
