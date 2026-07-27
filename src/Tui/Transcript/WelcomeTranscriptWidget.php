<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\TuiTheme;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Ansi\TextWrapper;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Welcome placeholder when the transcript has no visible blocks.
 *
 * Honors the live renderer width contract: wrap when possible, truncate when the
 * terminal is too narrow for a single word.
 */
final class WelcomeTranscriptWidget extends AbstractWidget
{
    private const string MESSAGE = '  Welcome to Agent Core. Type a message below to start.';

    public function __construct(
        private readonly TuiTheme $theme,
    ) {
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $width = max($context->getColumns(), 1);
        $styled = $this->theme->muted(self::MESSAGE);
        $lines = TextWrapper::wrapTextWithAnsi($styled, $width);
        if ([] === $lines) {
            return [$this->theme->muted(AnsiUtils::truncateToWidth(self::MESSAGE, $width, ellipsis: '…'))];
        }

        $safe = [];
        foreach ($lines as $line) {
            // Guard pathological narrow widths where wrap still exceeds columns.
            if (AnsiUtils::visibleWidth($line) > $width) {
                $safe[] = AnsiUtils::truncateToWidth($line, $width, ellipsis: '…');
                continue;
            }
            $safe[] = $line;
        }

        return $safe;
    }
}
