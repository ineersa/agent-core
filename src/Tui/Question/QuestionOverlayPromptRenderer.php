<?php

declare(strict_types=1);

namespace Ineersa\Tui\Question;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Style\Padding;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\MarkdownWidget;
use Symfony\Component\Tui\Widget\TextWidget;
use Symfony\Component\Tui\Widget\Util\StringUtils;

/**
 * Compact markdown prompt rendering for active HITL overlays (QuestionController).
 */
final class QuestionOverlayPromptRenderer
{
    public function buildPromptWidget(string $prompt, TuiTheme $theme): MarkdownWidget
    {
        $mdWidget = new MarkdownWidget($prompt);
        // Prompt body uses the theme-owned Prompt color so it differs from answer rows.
        // Accent stays reserved for the compact header line.
        $promptColor = $theme->getPalette()->get(ThemeColorEnum::Prompt);
        $mdWidget->setStyle(new Style(
            padding: Padding::from([0, 0, 0, 2]),
            color: '' !== $promptColor ? $promptColor : null,
        ));

        return $mdWidget;
    }

    /**
     * Render SafeGuard's classified trigger input without Markdown parsing.
     * Match offsets originate with the classifier, not this presentation layer.
     *
     * @param list<array{start: int, length: int}> $matchSpans
     */
    public function buildTriggerInputWidget(
        string $label,
        string $input,
        array $matchSpans,
        TuiTheme $theme,
    ): TextWidget {
        $spans = $this->mergedSpans($input, $matchSpans);
        $text = $this->safeText($label).":\n";
        $cursor = 0;
        $warningColor = $theme->getPalette()->get(ThemeColorEnum::Warning);
        $highlight = new Style(
            color: '' !== $warningColor ? $warningColor : null,
            bold: true,
        );

        foreach ($spans as $span) {
            $text .= $this->safeText(substr($input, $cursor, $span['start'] - $cursor));
            $text .= $highlight->apply($this->safeText(substr($input, $span['start'], $span['length'])));
            $cursor = $span['start'] + $span['length'];
        }
        $text .= $this->safeText(substr($input, $cursor));

        return new TextWidget(text: $text, truncate: false);
    }

    public function buildIndentedHeader(string $text, TuiTheme $theme): TextWidget
    {
        return new TextWidget(
            text: $theme->color(ThemeColorEnum::Accent, '  '.$text),
            truncate: false,
        );
    }

    public function buildIndentedHint(string $text, TuiTheme $theme): TextWidget
    {
        return new TextWidget(
            text: $theme->muted('  '.$text),
            truncate: false,
        );
    }

    /**
     * @param list<array{start: int, length: int}> $matchSpans
     *
     * @return list<array{start: int, length: int}>
     */
    private function mergedSpans(string $input, array $matchSpans): array
    {
        $length = \strlen($input);
        $spans = [];
        foreach ($matchSpans as $span) {
            $spanLength = min($span['length'], $length - $span['start']);
            if ($span['start'] >= 0
                && $span['start'] < $length
                && $spanLength > 0
                && $this->isUtf8Boundary($input, $span['start'])
                && $this->isUtf8Boundary($input, $span['start'] + $spanLength)
            ) {
                $spans[] = [
                    'start' => $span['start'],
                    'length' => $spanLength,
                ];
            }
        }
        usort($spans, static fn (array $left, array $right): int => [$left['start'], $left['length']] <=> [$right['start'], $right['length']]);

        $merged = [];
        foreach ($spans as $span) {
            $last = array_key_last($merged);
            if (null !== $last && $span['start'] <= $merged[$last]['start'] + $merged[$last]['length']) {
                $merged[$last]['length'] = max($merged[$last]['length'], $span['start'] + $span['length'] - $merged[$last]['start']);
                continue;
            }
            $merged[] = $span;
        }

        return $merged;
    }

    private function isUtf8Boundary(string $input, int $offset): bool
    {
        if (0 === $offset || \strlen($input) === $offset) {
            return true;
        }

        return 0x80 !== (\ord($input[$offset]) & 0xC0);
    }

    private function safeText(string $text): string
    {
        // TextWidget renders literal text rather than Symfony markup but passes ANSI
        // through. Normalize malformed UTF-8 first, then represent Unicode control
        // code points visibly (except meaningful tab/newline) so valid continuation
        // bytes are never mistaken for C1 controls and terminal injection is inert.
        $text = StringUtils::sanitizeUtf8($text);
        $escaped = preg_replace_callback(
            '/[\x{00}-\x{08}\x{0B}-\x{1F}\x{7F}-\x{9F}]/u',
            static fn (array $match): string => \sprintf('\\x%02X', mb_ord($match[0])),
            $text,
        );
        if (null === $escaped) {
            throw new \LogicException('Unable to escape SafeGuard trigger text.');
        }

        return $escaped;
    }
}
