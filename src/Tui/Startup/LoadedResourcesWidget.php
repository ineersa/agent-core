<?php

declare(strict_types=1);

namespace Ineersa\Tui\Startup;

use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceConflictDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceItemDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceSectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryDTO;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Pi-style loaded-resources block for TUI startup (display-only).
 */
final class LoadedResourcesWidget extends AbstractWidget
{
    private ?LoadedResourcesSummaryDTO $summary = null;
    private bool $expanded = false;

    /** @var array<string, string> */
    private array $extensionWarnings = [];

    public function __construct(
        private readonly TuiTheme $theme,
    ) {
    }

    public function setSummary(?LoadedResourcesSummaryDTO $summary): void
    {
        $this->summary = $summary;
        $this->invalidate();
    }

    public function toggleExpanded(): void
    {
        $this->expanded = !$this->expanded;
        $this->invalidate();
    }

    public function setExtensionWarning(string $name, ?string $message): void
    {
        if (($this->extensionWarnings[$name] ?? null) === $message) {
            return;
        }

        if (null === $message) {
            unset($this->extensionWarnings[$name]);
        } else {
            $this->extensionWarnings[$name] = $message;
        }
        $this->invalidate();
    }

    public function hasContent(): bool
    {
        return [] !== $this->extensionWarnings || (null !== $this->summary && [] !== $this->summary->nonEmptySections());
    }

    /** @return string[] */
    public function render(RenderContext $context): array
    {
        if (!$this->hasContent()) {
            return [];
        }

        $sections = $this->summary?->nonEmptySections() ?? [];
        $lines = [];
        $hasExtensions = false;

        foreach ($sections as $section) {
            $lines = array_merge($lines, $this->renderSection($section));
            if ('Extensions' === $section->label) {
                $hasExtensions = true;
            }
        }
        if (!$hasExtensions && [] !== $this->extensionWarnings) {
            $lines = array_merge($lines, $this->renderSection(new LoadedResourceSectionDTO('Extensions', [])));
        }

        $hint = $this->expanded
            ? 'Press ctrl+r to collapse source paths'
            : 'Press ctrl+r to expand source paths';
        $lines[] = $this->theme->muted('  '.$hint);

        // Same wrap primitive the LiveTextWidget adapter used: long compact
        // lists reflow on narrow terminals instead of overflowing.
        return TextWrapper::wrapTextWithAnsi(implode("\n", $lines), $context->getColumns());
    }

    /**
     * @return list<string>
     */
    private function renderSection(LoadedResourceSectionDTO $section): array
    {
        $theme = $this->theme;
        $lines = [];
        $header = $theme->color(ThemeColorEnum::MarkdownHeading, '['.$section->label.']');
        if ([] === $section->items) {
            $lines[] = $header;
        } else {
            $lines[] = $header.'  '.$theme->muted($this->formatCompactList($section->items));
        }

        if ($this->expanded) {
            foreach ($section->items as $item) {
                $lines[] = $theme->muted('  '.$this->formatExpandedItem($item));
            }
        }

        foreach ($section->conflicts as $conflict) {
            $lines[] = $theme->warning('  '.$this->formatConflict($conflict));
        }

        if ('Extensions' === $section->label) {
            foreach ($this->extensionWarnings as $name => $message) {
                $lines[] = $theme->warning('  ⚠ '.$name.': '.$message);
            }
        }

        return $lines;
    }

    /**
     * @param list<LoadedResourceItemDTO> $items
     */
    private function formatCompactList(array $items): string
    {
        if ([] === $items) {
            return '(none)';
        }

        $parts = [];
        foreach ($items as $item) {
            $label = $item->name;
            if ($item->disabled) {
                $label .= ' (disabled)';
            }
            $parts[] = $label;
        }

        return implode(', ', $parts);
    }

    private function formatExpandedItem(LoadedResourceItemDTO $item): string
    {
        $path = '' !== $item->sourcePath ? $item->sourcePath : '(no path)';
        $suffix = $item->disabled ? ' (disabled)' : '';

        return $item->name.$suffix.' — '.$path;
    }

    private function formatConflict(LoadedResourceConflictDTO $conflict): string
    {
        $name = '' !== $conflict->name ? $conflict->name : 'resource';
        $winner = '' !== $conflict->winnerPath ? $conflict->winnerPath : '(unknown)';
        $loser = '' !== $conflict->loserPath ? $conflict->loserPath : '(unknown)';

        if ('' !== $conflict->message && '' === $conflict->winnerPath) {
            return '⚠ '.$name.': '.$conflict->message;
        }

        if ('' !== $conflict->message && ('' !== $conflict->winnerPath || '' !== $conflict->loserPath)) {
            return \sprintf('⚠ %s: %s (won %s, ignored %s)', $name, $conflict->message, $winner, $loser);
        }

        return \sprintf('⚠ %s: won %s, ignored %s', $name, $winner, $loser);
    }
}
