<?php

declare(strict_types=1);

namespace Ineersa\Tui\Startup;

use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceConflictDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceItemDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourceSectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryDTO;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;

/**
 * Pi-style loaded-resources block for TUI startup (display-only).
 */
final class LoadedResourcesWidget implements TuiWidget
{
    private ?LoadedResourcesSummaryDTO $summary = null;
    private bool $expanded = false;

    public function setSummary(?LoadedResourcesSummaryDTO $summary): void
    {
        $this->summary = $summary;
    }

    public function setExpanded(bool $expanded): void
    {
        $this->expanded = $expanded;
    }

    public function isExpanded(): bool
    {
        return $this->expanded;
    }

    public function toggleExpanded(): void
    {
        $this->expanded = !$this->expanded;
    }

    public function hasContent(): bool
    {
        return null !== $this->summary && [] !== $this->summary->nonEmptySections();
    }

    /** @return list<string> */
    public function render(TuiRenderContext $context): array
    {
        if (null === $this->summary) {
            return [];
        }

        $sections = $this->summary->nonEmptySections();
        if ([] === $sections) {
            return [];
        }

        $lines = [];

        foreach ($sections as $section) {
            $lines = array_merge($lines, $this->renderSection($section, $context));
        }

        $hint = $this->expanded
            ? 'Press ctrl+r to collapse source paths'
            : 'Press ctrl+r to expand source paths';
        $lines[] = $context->theme->muted('  '.$hint);

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function renderSection(LoadedResourceSectionDTO $section, TuiRenderContext $context): array
    {
        $theme = $context->theme;
        $lines = [];
        $header = $theme->color(ThemeColorEnum::MarkdownHeading, '['.$section->label.']');
        $compact = $this->formatCompactList($section->items);
        $lines[] = $header.'  '.$theme->muted($compact);

        if ($this->expanded) {
            foreach ($section->items as $item) {
                $lines[] = $theme->muted('  '.$this->formatExpandedItem($item));
            }
        }

        foreach ($section->conflicts as $conflict) {
            $lines[] = $theme->warning('  '.$this->formatConflict($conflict));
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

        if ('' !== $conflict->message) {
            return '⚠ '.$name.': '.$conflict->message;
        }

        return \sprintf('⚠ %s: won %s, ignored %s', $name, $winner, $loser);
    }
}
