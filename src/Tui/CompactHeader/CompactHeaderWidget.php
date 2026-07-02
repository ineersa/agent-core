<?php

declare(strict_types=1);

namespace Ineersa\Tui\CompactHeader;

use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;
use Symfony\Component\Tui\Ansi\AnsiUtils;

/**
 * Pi-style permanent capability bar (prompts, skills, agents, mcp) above the editor merge.
 */
final class CompactHeaderWidget implements TuiWidget
{
    private const LABEL_WIDTH = 9;

    private const AGENTS_LIVE_HINT = '/agents-live';

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
                static fn (string $name): string => $theme->accent('/'.$name),
                $this->snapshot->prompts,
            );
            $lines = array_merge($lines, $this->wrapLabel($theme->muted('prompts'), $items, $width));
        }

        if ([] !== $this->snapshot->skills) {
            $items = array_map(
                static fn (string $name): string => $theme->accent('skill:'.$name),
                $this->snapshot->skills,
            );
            $lines = array_merge($lines, $this->wrapLabel($theme->muted('skills'), $items, $width));
        }

        if ($this->snapshot->agentCount > 0) {
            $agentsValue = $theme->accent((string) $this->snapshot->agentCount.' available')
                .' '.$theme->muted('•').' '.$theme->accent(self::AGENTS_LIVE_HINT);
            $lines[] = $this->truncateLine($this->padLabel($theme->muted('agents')).$agentsValue, $width);
        }

        if ([] !== $this->snapshot->agentNames) {
            $items = array_map(
                static fn (string $name): string => $theme->accent($name),
                $this->formatAgentNameItems($this->snapshot->agentNames),
            );
            $lines = array_merge($lines, $this->wrapLabel($theme->muted('available'), $items, $width));
        }

        if ([] !== $this->snapshot->mcpServers) {
            $items = [];
            foreach ($this->snapshot->mcpServers as $server) {
                $suffix = null !== $server->toolCount ? ' ('.$server->toolCount.')' : '';
                $items[] = $theme->accent($server->icon.' '.$server->name.$suffix.': '.$server->statusText);
            }
            $lines = array_merge($lines, $this->wrapLabel($theme->muted('mcp'), $items, $width));
        }

        return $lines;
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private function formatAgentNameItems(array $names): array
    {
        $counts = [];
        foreach ($names as $name) {
            $counts[$name] = ($counts[$name] ?? 0) + 1;
        }
        ksort($counts, \SORT_STRING);
        $items = [];
        foreach ($counts as $name => $count) {
            $items[] = $count > 1 ? $name.'×'.$count : $name;
        }

        return $items;
    }

    /**
     * @param list<string> $items Styled item strings (may contain ANSI)
     *
     * @return list<string>
     */
    private function wrapLabel(string $label, array $items, int $width): array
    {
        if ([] === $items) {
            return [];
        }

        $labelPad = $this->padLabel($label);
        $indent = str_repeat(' ', self::LABEL_WIDTH);
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
                    $prefixWidth = self::LABEL_WIDTH;
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

    private function padLabel(string $label): string
    {
        $pad = max(0, self::LABEL_WIDTH - AnsiUtils::visibleWidth($label));

        return $label.str_repeat(' ', $pad);
    }

    private function truncateLine(string $line, int $width): string
    {
        if (AnsiUtils::visibleWidth($line) <= $width) {
            return $line;
        }

        return AnsiUtils::truncateToWidth($line, $width);
    }
}
