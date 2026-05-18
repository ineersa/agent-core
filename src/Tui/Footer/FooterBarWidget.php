<?php

declare(strict_types=1);

namespace Ineersa\Tui\Footer;

use Ineersa\Tui\Theme\ThemeColor;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;

/**
 * Default footer bar widget.
 *
 * Renders a compact single line of segments from the FooterDataProvider.
 * Each segment may carry an optional ThemeColor — when present, it is
 * wrapped in the theme's ANSI formatting before joining.
 *
 * Segment grouping: consecutive segments whose priorities differ by < 5
 * are spaced with whitespace; gaps >= 5 produce a "  |  " separator so
 * multi-colored token/cost blocks stay visually grouped.
 *
 * Truncation is ANSI-aware — escape sequences are not counted toward
 * visible width, and truncation preserves them so remaining segments
 * keep their intended colors.
 */
final class FooterBarWidget implements TuiWidget
{
    private const int GROUP_SEPARATOR_GAP = 5;

    public function __construct(
        private readonly FooterDataProvider $dataProvider,
    ) {
    }

    /** @return list<string> */
    public function render(TuiRenderContext $context): array
    {
        $segments = $this->dataProvider->getSegments();
        $statusEntries = $this->dataProvider->getStatusEntries();

        if ([] === $segments && [] === $statusEntries) {
            return [$context->theme->color(ThemeColor::Footer, '  ◆ agent-core  |  type /help for commands')];
        }

        // Build left-side parts with per-segment coloring and smart separators.
        $renderedParts = [];
        $prevPriority = null;

        foreach ($segments as $segment) {
            $text = $segment->text;
            if (null !== $segment->color) {
                $text = $context->theme->color($segment->color, $text);
            }

            // Insert separator before this segment (skip first)
            if (null !== $prevPriority) {
                $gap = $segment->priority - $prevPriority;
                if ($gap >= self::GROUP_SEPARATOR_GAP) {
                    $renderedParts[] = $context->theme->color(ThemeColor::Dim, '  |  ');
                } else {
                    $renderedParts[] = ' ';
                }
            }

            $renderedParts[] = $text;
            $prevPriority = $segment->priority;
        }

        $left = implode('', $renderedParts);

        // Build right-side status text from entries
        $statusParts = [];
        foreach ($statusEntries as $text) {
            $statusParts[] = $text;
        }

        $right = implode(' ', $statusParts);
        $separator = ('' !== $right) ? '  ' : '';

        // ANSI-aware truncation of the left part if combined exceeds available width
        $combined = \sprintf('%s%s%s', $left, $separator, $right);
        $available = max(10, $context->terminalWidth - 2);

        if (self::visibleWidth($combined) > $available) {
            // Calculate how much visible width the right side + separator take
            $rightVisible = self::visibleWidth($separator.$right);
            $leftMax = max(0, $available - $rightVisible);
            $left = self::truncateToWidth($left, $leftMax);
            $combined = \sprintf('%s%s%s', $left, $separator, $right);
        }

        return [\sprintf('  %s', $combined)];
    }

    /**
     * Calculate visible character width, ignoring ANSI escape sequences.
     *
     * Uses mb_strwidth for Unicode glyph width (e.g., ◆ is 2 columns wide).
     */
    private static function visibleWidth(string $text): int
    {
        // Use \e (PCRE escape for ESC) so the pattern works regardless of
        // PHP string quoting.  \/ inside the char class avoids the forward
        // slash being interpreted as the regex delimiter.
        $plain = preg_replace('/\e\[[0-?]*[ -\/]*[@-~]/', '', $text);

        return mb_strwidth($plain ?? $text);
    }

    /**
     * Truncate a string to a maximum visible width while preserving ANSI
     * escape sequences. Appends '...' when truncation occurs.
     *
     * Uses character-by-character parsing so that ANSI codes do not consume
     * the visible width budget.
     */
    private static function truncateToWidth(string $text, int $maxWidth): string
    {
        if (self::visibleWidth($text) <= $maxWidth) {
            return $text;
        }

        $result = '';
        $visible = 0;
        $len = \strlen($text);
        $max = $maxWidth - 3; // Reserve space for '...'
        $i = 0;

        while ($i < $len && $visible < $max) {
            // Detect ANSI escape: ESC [ ... letter
            // chr(27) === \x1b
            if ("\x1b" === $text[$i] && $i + 1 < $len && '[' === $text[$i + 1]) {
                $seqStart = $i;
                $i += 2;
                while ($i < $len && self::isAnsiIntermediate($text[$i])) {
                    ++$i;
                }
                if ($i < $len) {
                    ++$i; // Consume the final byte
                }
                $result .= substr($text, $seqStart, $i - $seqStart);

                continue;
            }

            $charWidth = mb_strwidth($text[$i]);

            if ($visible + $charWidth > $max) {
                break;
            }

            $result .= $text[$i];
            $visible += $charWidth;
            ++$i;
        }

        $result .= '...';

        return $result;
    }

    /**
     * Check if a byte is part of an ANSI CSI parameter or intermediate byte.
     *
     * CSI sequences: ESC [ params intermediate* final_byte
     * Parameter bytes: 0x30–0x3F (0–? @ A–?)
     * Intermediate bytes: 0x20–0x2F (space through /)
     * Final byte: 0x40–0x7E (@ through ~)
     */
    private static function isAnsiIntermediate(string $byte): bool
    {
        $ord = \ord($byte);

        // Parameter bytes (0x30-0x3F) or intermediate bytes (0x20-0x2F)
        return ($ord >= 0x20 && $ord <= 0x2F) || ($ord >= 0x30 && $ord <= 0x3F);
    }
}
