<?php

declare(strict_types=1);

namespace Ineersa\Tui\Widget;

use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;
use Symfony\Component\Tui\Widget\VerticallyExpandableInterface;

/**
 * A Symfony TUI widget whose content is produced by a callable on every render.
 *
 * Unlike {@see \Symfony\Component\Tui\Widget\TextWidget}, which stores a
 * fixed pre-computed string, this widget invokes the producer closure with
 * the live {@see RenderContext} on each render, so the output always matches
 * the current terminal width and application state.
 *
 * The render cache (keyed on revision × columns × rows) still works:
 * - Calls to {@see AbstractWidget::invalidate()} force re-computation.
 * - Terminal resize (columns change) automatically causes a cache miss.
 *
 * Blank lines are preserved (produce empty-string array entries), which
 * makes this widget suitable for top-margin / spacer rows.
 */
final class LiveTextWidget extends AbstractWidget implements VerticallyExpandableInterface
{
    /** @var \Closure(RenderContext): string */
    private \Closure $producer;

    /**
     * @param \Closure(RenderContext): string $producer called on every
     *                                                  render tick; receives the live context so the returned text
     *                                                  can be sized to the current terminal width
     * @param bool                            $truncate when true, truncate each line to fit width
     *                                                  instead of word-wrapping
     */
    public function __construct(
        callable $producer,
        private readonly bool $truncate = false,
    ) {
        $this->producer = $producer(...);
    }

    private bool $verticallyExpanded = false;

    public function expandVertically(bool $expand): static
    {
        if ($this->verticallyExpanded !== $expand) {
            $this->verticallyExpanded = $expand;
            $this->invalidate();
        }

        return $this;
    }

    public function isVerticallyExpanded(): bool
    {
        return $this->verticallyExpanded;
    }

    /** @return string[] */
    public function render(RenderContext $context): array
    {
        $text = ($this->producer)($context);

        // Normalize tabs to 3 spaces (consistent with TextWidget)
        $normalized = str_replace("\t", '   ', $text);

        // An empty string means "no content" — do not render a blank row.
        // (Top margin / spacer rows use explicit newlines, e.g. "\n\n\n",
        // which are preserved because '' !== "\n\n\n".)
        if ('' === $normalized) {
            return [];
        }

        $cols = $context->getColumns();

        if ($this->truncate) {
            $lines = explode("\n", $normalized);
            $result = [];
            foreach ($lines as $line) {
                $result[] = AnsiUtils::truncateToWidth($line, $cols);
            }

            return $result;
        }

        $lines = TextWrapper::wrapTextWithAnsi($normalized, $cols);

        if ($this->verticallyExpanded) {
            $rows = $context->getRows();
            if ($rows > 0 && \count($lines) > $rows) {
                $lines = \array_slice($lines, -$rows);
            }
        }

        return $lines;
    }
}
