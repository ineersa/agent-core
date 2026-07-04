<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\TuiTheme;

/**
 * Single-line picker row labels with transcript-like role prefixes.
 */
final class PickerListLabelFormatter
{
    public static function sanitizeTitle(string $title): string
    {
        $text = str_replace(["\r\n", "\r", "\n"], ' ', $title);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        $text = trim($text);
        if ('' === $text) {
            return '';
        }

        // Drop common markdown/blockquote/list continuation noise for one-line rows.
        $text = preg_replace('/^>\s*/u', '', $text) ?? $text;
        $text = preg_replace('/^[-*]\s+/u', '', $text) ?? $text;
        $text = preg_replace('/^#+\s+/u', '', $text) ?? $text;

        return trim($text);
    }

    public static function formatRolePrefix(TuiTheme $theme, string $displayRole): string
    {
        return match ($displayRole) {
            'user' => $theme->color(ThemeColorEnum::UserMessage, 'user:'),
            'tool' => $theme->color(ThemeColorEnum::ToolTitle, '[tool:]'),
            default => $theme->color(ThemeColorEnum::AssistantMessage, 'assistant:'),
        };
    }
}
