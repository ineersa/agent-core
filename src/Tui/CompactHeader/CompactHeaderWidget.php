<?php

declare(strict_types=1);

namespace Ineersa\Tui\CompactHeader;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Pi-style permanent capability bar (prompts, skills, agents, mcp) above the editor merge.
 */
final class CompactHeaderWidget implements TuiWidget
{
    private const LABEL_WIDTH = 9;

    private const VALUE_START_COL = 11;

    private ?CompactHeaderSnapshot $snapshot = null;

    public function setSnapshot(?CompactHeaderSnapshot $snapshot): void
    {
        $this->snapshot = $snapshot;
    }

    /** @return list<string> */
    public function render(TuiRenderContext $context): array
    {
        if (null === $this->snapshot || $this->snapshot->isEmpty()) {
            return [];
        }

        $theme = $context->theme;
        $width = $context->terminalWidth;
        $lines = [];

        if ([] !== $this->snapshot->prompts) {
            $items = array_map(
                static fn (string $name): string => $theme->color(ThemeColorEnum::MarkdownLink, '/'.$name),
                $this->snapshot->prompts,
            );
            $lines = array_merge($lines, $this->wrapLabel('prompts', $items, $width, $theme));
        }

        if ([] !== $this->snapshot->skills) {
            $items = array_map(
                static fn (string $name): string => $theme->color(ThemeColorEnum::MarkdownLink, $name),
                $this->snapshot->skills,
            );
            $lines = array_merge($lines, $this->wrapLabel('skills', $items, $width, $theme));
        }

        if ([] !== $this->snapshot->agentNames) {
            $agentNames = $this->snapshot->agentNames;
            sort($agentNames, \SORT_STRING);
            $items = array_map(
                static fn (string $name): string => $theme->color(ThemeColorEnum::MarkdownLink, $name),
                $agentNames,
            );
            $lines = array_merge($lines, $this->wrapLabel('agents', $items, $width, $theme));
        }

        if ([] !== $this->snapshot->mcpServers) {
            $items = [];
            foreach ($this->snapshot->mcpServers as $server) {
                $iconStyled = $this->mcpIconStyled($theme, $server);
                $countSuffix = null !== $server->toolCount
                    ? ' '.$theme->muted('('.$server->toolCount.')')
                    : '';
                $items[] = $iconStyled.$theme->color(ThemeColorEnum::MarkdownLink, $server->name).$countSuffix;
            }
            $lines = array_merge($lines, $this->wrapLabel('mcp', $items, $width, $theme));
        }

        return $lines;
    }

    private function mcpIconStyled(TuiTheme $theme, McpServerHeaderEntry $server): string
    {
        if (!$server->isConnected) {
            return $theme->error('✗').' ';
        }

        if ($server->isGlobal) {
            return $theme->success('✓').' ';
        }

        return $theme->color(ThemeColorEnum::MarkdownLink, '◈').' ';
    }

    /**
     * @param list<string> $items Styled item strings (may contain ANSI)
     *
     * @return list<string>
     */
    private function wrapLabel(string $label, array $items, int $width, TuiTheme $theme): array
    {
        if ([] === $items) {
            return [];
        }

        $labelStyled = $theme->color(ThemeColorEnum::MarkdownHeading, $label);
        $labelPad = $this->padLabel($labelStyled, $theme);
        $indent = str_repeat(' ', self::VALUE_START_COL);
        $lines = [];
        $first = true;
        $currentLine = '';
        $currentWidth = 0;
        $prefixWidth = AnsiUtils::visibleWidth($labelPad);

        foreach ($items as $item) {
            $itemWidth = AnsiUtils::visibleWidth($item);
            $gapWidth = '' !== $currentLine ? 2 : 0;
            $totalWidth = $prefixWidth + $currentWidth + $gapWidth + $itemWidth;

            if ($totalWidth <= $width) {
                $currentLine .= ('' !== $currentLine ? '  ' : '').$item;
                $currentWidth += $gapWidth + $itemWidth;
            } else {
                if ('' !== $currentLine) {
                    $lines[] = $this->truncateLine(($first ? $labelPad : $indent).$currentLine, $width);
                    $first = false;
                    $prefixWidth = self::VALUE_START_COL;
                }
                $currentLine = $item;
                $currentWidth = $itemWidth;
            }
        }

        if ('' !== $currentLine) {
            $lines[] = $this->truncateLine(($first ? $labelPad : $indent).$currentLine, $width);
        }

        return $lines;
    }

    private function padLabel(string $label, TuiTheme $theme): string
    {
        $pad = max(0, self::LABEL_WIDTH - AnsiUtils::visibleWidth($label));

        return $label.str_repeat(' ', $pad)
            .$theme->color(ThemeColorEnum::BorderMuted, '│').' ';
    }

    private function truncateLine(string $line, int $width): string
    {
        if (AnsiUtils::visibleWidth($line) <= $width) {
            return $line;
        }

        return AnsiUtils::truncateToWidth($line, $width);
    }
}
