<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\TuiTheme;

/**
 * Renders a theme-colored hotkeys table from plain binding data.
 *
 * This is a pure renderer — it has no knowledge of {@see HotkeyRegistry}
 * or {@see HotkeyBindingDTO}. It receives grouped binding arrays and the
 * active theme, and produces ANSI-styled Unicode box-drawing table output.
 *
 * Lives in TuiTranscript layer so it can access TuiTheme without
 * violating deptrac boundaries (TuiTranscript → TuiTheme is allowed).
 *
 * Theme color plan:
 *  - Section headings → Accent
 *  - Table borders    → Muted
 *  - Header row       → Accent
 *  - Key names        → Success
 *  - Action names     → Text (default body, no explicit wrapping)
 *  - Descriptions     → Muted
 *  - Footer note      → Muted
 */
final readonly class HotkeyTableRenderer
{
    /**
     * Width caps for table columns (display columns).
     */
    private const MAX_KEY_WIDTH = 30;
    private const MAX_ACTION_WIDTH = 35;
    private const MAX_DESC_WIDTH = 45;

    /**
     * Render a themed hotkeys table.
     *
     * @param array<string, list<array{keys: list<string>, action: string, description: string}>> $groups
     *                                                                                                          grouped bindings keyed by context name (e.g. 'Global' → [...], 'Editor' → [...])
     * @param string                                                                              $emptyMessage message shown when groups is empty
     */
    public function render(array $groups, TuiTheme $theme, string $emptyMessage = ''): string
    {
        if ([] === $groups) {
            if ('' === $emptyMessage) {
                $emptyMessage = 'No hotkey hints registered. '
                    .'This is a bug — hotkeys should be populated during TUI startup.';
            }

            return $theme->muted($emptyMessage);
        }

        $lines = [];
        $lines[] = $theme->accent('Keyboard shortcuts');
        $lines[] = '';

        foreach ($groups as $context => $bindings) {
            $lines[] = '  '.$theme->accent($context);
            $lines[] = '';
            foreach ($this->buildContextTable($bindings, $theme) as $row) {
                $lines[] = $row;
            }
            $lines[] = '';
        }

        $lines[] = $theme->muted(
            'App shortcuts (Ctrl+C, Ctrl+D) are global and cannot be remapped.',
        );
        $lines[] = $theme->muted(
            'Editor bindings reflect the current keymap and may differ from defaults.',
        );

        return implode("\n", $lines);
    }

    // ─── Display-width helpers ────────────────────────────────

    /**
     * Pad a string to the given display-column width using spaces.
     *
     * Uses mb_strwidth so multi-byte Unicode characters (e.g. ↑, ↓)
     * are measured in terminal columns rather than bytes.
     */
    public static function padDisplayWidth(string $text, int $targetWidth): string
    {
        $current = mb_strwidth($text);
        if ($current >= $targetWidth) {
            return $text;
        }

        return $text.str_repeat(' ', $targetWidth - $current);
    }

    /**
     * Truncate a string to fit within target display width, then pad.
     *
     * Strings longer than the target are truncated with a single
     * '…' (U+2026, one display column) appended.
     */
    public static function truncPadDisplayWidth(string $text, int $targetWidth): string
    {
        $current = mb_strwidth($text);
        if ($current <= $targetWidth) {
            return self::padDisplayWidth($text, $targetWidth);
        }

        $maxLen = mb_strlen($text);
        for ($i = $maxLen; $i > 0; --$i) {
            $prefix = mb_substr($text, 0, $i);
            if (mb_strwidth($prefix) + 1 <= $targetWidth) {
                return self::padDisplayWidth($prefix.'…', $targetWidth);
            }
        }

        return self::padDisplayWidth('…', $targetWidth);
    }

    /**
     * Format a key identifier for user display.
     *
     * Examples:
     *   'ctrl+c' → 'Ctrl+C'
     *   'shift+enter' → 'Shift+Enter'
     *   'up' → '↑'
     *
     * Case-insensitive; returns the input unmodified for unrecognized keys.
     */
    public static function formatKeyDisplay(string $keyId): string
    {
        $normalized = strtolower(trim($keyId));
        $parts = explode('+', $normalized);
        $baseKey = array_pop($parts);

        $modifiers = array_map(
            static fn (string $m): string => match ($m) {
                'ctrl' => 'Ctrl',
                'shift' => 'Shift',
                'alt' => 'Alt',
                default => ucfirst($m),
            },
            $parts,
        );

        $formattedKey = match ($baseKey) {
            'up' => '↑',
            'down' => '↓',
            'left' => '←',
            'right' => '→',
            'enter' => 'Enter',
            'escape' => 'Esc',
            'tab' => 'Tab',
            'space' => 'Space',
            'backspace' => 'Bksp',
            'delete' => 'Del',
            'page_up' => 'PgUp',
            'page_down' => 'PgDn',
            'home' => 'Home',
            'end' => 'End',
            default => ucfirst($baseKey),
        };

        if ([] === $modifiers) {
            return $formattedKey;
        }

        return implode('+', array_merge($modifiers, [$formattedKey]));
    }

    /**
     * Build a theme-colored box-drawing table for one context.
     *
     * @param list<array{keys: list<string>, action: string, description: string}> $bindings
     *
     * @return list<string>
     */
    private function buildContextTable(array $bindings, TuiTheme $theme): array
    {
        $rows = [];
        $hasDesc = false;

        foreach ($bindings as $b) {
            $keysStr = implode(', ', array_map(
                static fn (string $k): string => self::formatKeyDisplay($k),
                $b['keys'],
            ));
            $desc = $b['description'];
            if ('' !== $desc) {
                $hasDesc = true;
            }
            $rows[] = [$keysStr, $b['action'], $desc];
        }

        $keyW = 0;
        $actW = 0;
        $descW = 0;

        foreach ($rows as [$k, $a, $d]) {
            $keyW = max($keyW, mb_strwidth($k));
            $actW = max($actW, mb_strwidth($a));
            if ($hasDesc) {
                $descW = max($descW, mb_strwidth($d));
            }
        }

        $keyW = min($keyW, self::MAX_KEY_WIDTH);
        $actW = min($actW, self::MAX_ACTION_WIDTH);
        if ($hasDesc) {
            $descW = min($descW, self::MAX_DESC_WIDTH);
        }

        $keyHeader = 'Keys';
        $actHeader = 'Action';
        $descHeader = 'Description';
        $keyW = max($keyW, mb_strwidth($keyHeader));
        $actW = max($actW, mb_strwidth($actHeader));
        if ($hasDesc) {
            $descW = max($descW, mb_strwidth($descHeader));
        }

        $result = [];
        $h = '─';
        $border = static fn (string $t): string => $theme->muted($t);
        $accent = static fn (string $t): string => $theme->accent($t);
        $success = static fn (string $t): string => $theme->success($t);
        $muted = static fn (string $t): string => $theme->muted($t);

        if ($hasDesc) {
            $result[] = '  '.$border(
                '┌'.$h.$h.$h.
                str_repeat($h, $keyW).'┬'.
                str_repeat($h, $actW + 2).'┬'.
                str_repeat($h, $descW + 2).'┐'
            );
            $result[] = '  '.
                $border('│').' '.$accent(self::padDisplayWidth($keyHeader, $keyW)).' '.
                $border('│').' '.$accent(self::padDisplayWidth($actHeader, $actW)).' '.
                $border('│').' '.$accent(self::padDisplayWidth($descHeader, $descW)).' '.
                $border('│');
            $result[] = '  '.$border(
                '├'.$h.$h.$h.
                str_repeat($h, $keyW).'┼'.
                str_repeat($h, $actW + 2).'┼'.
                str_repeat($h, $descW + 2).'┤'
            );

            foreach ($rows as [$k, $a, $d]) {
                $result[] = '  '.
                    $border('│').' '.$success(self::truncPadDisplayWidth($k, $keyW)).' '.
                    $border('│').' '.self::truncPadDisplayWidth($a, $actW).' '.
                    $border('│').' '.$muted(self::truncPadDisplayWidth($d, $descW)).' '.
                    $border('│');
            }

            $result[] = '  '.$border(
                '└'.$h.$h.$h.
                str_repeat($h, $keyW).'┴'.
                str_repeat($h, $actW + 2).'┴'.
                str_repeat($h, $descW + 2).'┘'
            );
        } else {
            $result[] = '  '.$border(
                '┌'.$h.$h.$h.
                str_repeat($h, $keyW).'┬'.
                str_repeat($h, $actW + 2).'┐'
            );
            $result[] = '  '.
                $border('│').' '.$accent(self::padDisplayWidth($keyHeader, $keyW)).' '.
                $border('│').' '.$accent(self::padDisplayWidth($actHeader, $actW)).' '.
                $border('│');
            $result[] = '  '.$border(
                '├'.$h.$h.$h.
                str_repeat($h, $keyW).'┼'.
                str_repeat($h, $actW + 2).'┤'
            );

            foreach ($rows as [$k, $a]) {
                $result[] = '  '.
                    $border('│').' '.$success(self::truncPadDisplayWidth($k, $keyW)).' '.
                    $border('│').' '.self::truncPadDisplayWidth($a, $actW).' '.
                    $border('│');
            }

            $result[] = '  '.$border(
                '└'.$h.$h.$h.
                str_repeat($h, $keyW).'┴'.
                str_repeat($h, $actW + 2).'┘'
            );
        }

        return $result;
    }
}
