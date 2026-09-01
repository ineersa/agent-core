<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Pending messages widget.
 *
 * Shows messages queued during compaction or processing.
 * For v1, renders nothing unless entries are explicitly set.
 */
final class PendingMessagesWidget extends AbstractWidget
{
    /** @var list<string> */
    private array $messages = [];

    public function __construct(
        private readonly TuiTheme $theme,
    ) {
    }

    /** @param list<string> $messages */
    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
        $this->invalidate();
    }

    public function clear(): void
    {
        $this->messages = [];
        $this->invalidate();
    }

    /** @return list<string> */
    public function messages(): array
    {
        return $this->messages;
    }

    /** @return string[] */
    public function render(RenderContext $context): array
    {
        if ([] === $this->messages) {
            return [];
        }

        $lines = [];
        foreach ($this->messages as $msg) {
            $lines[] = $this->theme->muted(\sprintf('%s %s', TranscriptGlyphs::GLYPH_PROGRESS, $msg));
        }

        return TextWrapper::wrapTextWithAnsi(implode("\n", $lines), $context->getColumns());
    }
}
