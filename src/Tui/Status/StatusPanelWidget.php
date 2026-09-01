<?php

declare(strict_types=1);

namespace Ineersa\Tui\Status;

use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Status panel widget that renders keyed status entries.
 *
 * Each entry renders as a line with a left-aligned label and text value.
 * Entries are pushed through {@see setEntries()} (ChatScreen::setStatus is
 * the production mutator) and the widget invalidates its render cache on
 * every mutation so the next tick repaints.
 */
final class StatusPanelWidget extends AbstractWidget
{
    /** @var array<string, string> */
    private array $entries = [];

    /**
     * @param array<string, string> $entries
     */
    public function __construct(
        private readonly TuiTheme $theme,
        array $entries = [],
    ) {
        $this->entries = $entries;
    }

    /**
     * Replace all status entries.
     *
     * @param array<string, string> $entries
     */
    public function setEntries(array $entries): void
    {
        $this->entries = $entries;
        $this->invalidate();
    }

    /** @return string[] */
    public function render(RenderContext $context): array
    {
        if ([] === $this->entries) {
            return [];
        }

        $lines = [];
        foreach ($this->entries as $key => $text) {
            $line = \sprintf('  %-12s %s', $key, $text);
            $lines[] = 'error' === $key
                ? $this->theme->error($line)
                : $this->theme->muted($line);
        }

        // Long status text wraps like the old LiveTextWidget adapter did.
        return TextWrapper::wrapTextWithAnsi(implode("\n", $lines), $context->getColumns());
    }
}
