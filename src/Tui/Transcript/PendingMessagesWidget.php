<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;

/**
 * Pending messages widget.
 *
 * Shows messages queued during compaction or processing.
 * For v1, renders nothing unless entries are explicitly set.
 */
final class PendingMessagesWidget implements TuiWidget
{
    /** @var list<string> */
    private array $messages = [];

    /** @param list<string> $messages */
    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    public function addMessage(string $message): void
    {
        $this->messages[] = $message;
    }

    public function clear(): void
    {
        $this->messages = [];
    }

    /** @return list<string> */
    public function messages(): array
    {
        return $this->messages;
    }

    /** @return list<string> */
    public function render(TuiRenderContext $context): array
    {
        if ([] === $this->messages) {
            return [];
        }

        $lines = [];
        foreach ($this->messages as $msg) {
            $lines[] = $context->theme->muted(\sprintf('  ⏳ %s', $msg));
        }

        return $lines;
    }
}
