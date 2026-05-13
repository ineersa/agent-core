<?php

declare(strict_types=1);

namespace Ineersa\Tui\Footer;

use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;

/**
 * Default footer bar widget.
 *
 * Renders a compact single line of segments from the FooterDataProvider.
 * Segments are separated by " · " and truncated to fit terminal width.
 * Built-in segments come from provider; extension segments merge in.
 */
final class FooterBarWidget implements TuiWidget
{
    public function __construct(
        private readonly FooterDataProvider $dataProvider,
    ) {
    }

    /** @return list<string> */
    public function render(TuiRenderContext $context): array
    {
        $segments = $this->dataProvider->getSegments();
        $statusEntries = $this->dataProvider->getStatusEntries();

        // Build right-side status text from entries
        $statusParts = [];
        foreach ($statusEntries as $text) {
            $statusParts[] = $text;
        }

        // Build combined footer line
        $parts = [];
        foreach ($segments as $segment) {
            $parts[] = $segment->text;
        }

        if ([] === $parts && [] === $statusParts) {
            return ['  ◆ agent-core  |  type /help for commands'];
        }

        $left = implode(' · ', $parts);
        $right = implode(' ', $statusParts);
        $separator = ('' !== $right) ? '  ' : '';

        // Truncate left if combined exceeds available width
        $combined = \sprintf('%s%s%s', $left, $separator, $right);
        $available = max(10, $context->terminalWidth - 2);

        if (mb_strlen($combined) > $available) {
            $maxLeft = max(0, $available - mb_strlen($separator) - mb_strlen($right));
            $left = mb_substr($left, 0, $maxLeft);
            $combined = \sprintf('%s%s%s', $left, $separator, $right);
        }

        return [\sprintf('  %s', $combined)];
    }
}
