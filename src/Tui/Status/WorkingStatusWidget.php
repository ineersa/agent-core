<?php

declare(strict_types=1);

namespace Ineersa\Tui\Status;

use Ineersa\Tui\Theme\ThemeColor;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;

/**
 * Working/status indicator widget.
 *
 * Displays an animated-ready dot with the current working message.
 * When visible and a message is set, shows:
 *   ● idle
 *   ◐ Working: processing...
 */
final class WorkingStatusWidget implements TuiWidget
{
    private string $message = '';
    private bool $visible = true;

    public function setMessage(?string $message): void
    {
        $this->message = $message ?? '';
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function setVisible(bool $visible): void
    {
        $this->visible = $visible;
    }

    public function isVisible(): bool
    {
        return $this->visible;
    }

    /** @return list<string> */
    public function render(TuiRenderContext $context): array
    {
        if (!$this->visible) {
            return [];
        }

        $indicator = '' !== $this->message
            ? \sprintf('◐ %s', $this->message)
            : '● idle';

        $line = \sprintf('  %s', $indicator);

        return [$context->theme->color(ThemeColor::Working, $line)];
    }
}
