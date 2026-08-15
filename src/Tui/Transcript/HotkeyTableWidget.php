<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Symfony\Component\Tui\Ansi\AnsiUtils;
use Symfony\Component\Tui\Render\RenderContext;
use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Style\StyleSheet;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Semantic hotkeys table for the local `/hotkeys` transcript block.
 *
 * Owns structured grouped bindings and recomputes ANSI-aware column widths
 * from {@see RenderContext} on every render. Style tokens are registered via
 * {@see self::styleSheetFromPalette()} (ChatScreen mount).
 */
final class HotkeyTableWidget extends AbstractWidget
{
    private const int MAX_KEY_WIDTH = 25;
    private const int MAX_ACTION_WIDTH = 30;
    private const int MAX_DESC_WIDTH = 40;
    private const int INDENT = 2;

    /**
     * @param array<string, list<array{keys: list<string>, action: string, description: string}>> $groups
     */
    public function __construct(
        private readonly array $groups,
        private readonly string $emptyMessage = '',
    ) {
    }

    public static function styleSheetFromPalette(ThemePalette $palette): StyleSheet
    {
        $rules = [];
        self::addRule($rules, 'heading', $palette, ThemeColorEnum::Accent);
        self::addRule($rules, 'context', $palette, ThemeColorEnum::Accent);
        self::addRule($rules, 'border', $palette, ThemeColorEnum::Muted);
        self::addRule($rules, 'header', $palette, ThemeColorEnum::Accent);
        self::addRule($rules, 'key', $palette, ThemeColorEnum::Success);
        self::addRule($rules, 'description', $palette, ThemeColorEnum::Muted);
        self::addRule($rules, 'footer', $palette, ThemeColorEnum::Muted);
        self::addRule($rules, 'empty', $palette, ThemeColorEnum::Muted);

        return new StyleSheet($rules);
    }

    /** @return list<string> */
    public function render(RenderContext $context): array
    {
        $width = max(1, $context->getColumns());

        if ([] === $this->groups) {
            $message = '' !== $this->emptyMessage
                ? $this->emptyMessage
                : 'No hotkey hints registered. '
                    .'This is a bug — hotkeys should be populated during TUI startup.';

            return [$this->fitLine($this->applyElement('empty', $message), $width)];
        }

        $lines = [];
        $lines[] = $this->fitLine($this->applyElement('heading', 'Keyboard shortcuts'), $width);
        $lines[] = $this->fitLine('', $width);

        foreach ($this->groups as $contextName => $bindings) {
            $lines[] = $this->fitLine($this->applyElement('context', '  '.$contextName), $width);
            $lines[] = $this->fitLine('', $width);
            foreach ($this->buildContextTable($bindings, $width) as $row) {
                $lines[] = $row;
            }
            $lines[] = $this->fitLine('', $width);
        }

        $lines[] = $this->fitLine(
            $this->applyElement('footer', 'App shortcuts (Ctrl+C, Ctrl+D) are global and cannot be remapped.'),
            $width,
        );
        $lines[] = $this->fitLine(
            $this->applyElement('footer', 'Editor bindings reflect the current keymap and may differ from defaults.'),
            $width,
        );

        return $lines;
    }

    /**
     * Format a key identifier for user display.
     *
     * Examples: 'ctrl+c' → 'Ctrl+C', 'up' → '↑'.
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
     * @param array<string, Style> $rules
     */
    private static function addRule(array &$rules, string $element, ThemePalette $palette, ThemeColorEnum $token): void
    {
        $spec = $palette->get($token);
        if ('' === $spec) {
            return;
        }

        $rules[self::class.'::'.$element] = new Style(color: $spec);
    }

    /**
     * @param list<array{keys: list<string>, action: string, description: string}> $bindings
     *
     * @return list<string>
     */
    private function buildContextTable(array $bindings, int $width): array
    {
        $rows = [];
        $hasDesc = false;

        foreach ($bindings as $binding) {
            $keysStr = implode(', ', array_map(
                static fn (string $k): string => self::formatKeyDisplay($k),
                $binding['keys'],
            ));
            $desc = $binding['description'];
            if ('' !== $desc) {
                $hasDesc = true;
            }
            $rows[] = [$keysStr, $binding['action'], $desc];
        }

        $keyHeader = 'Keys';
        $actHeader = 'Action';
        $descHeader = 'Description';

        $keyW = max(AnsiUtils::visibleWidth($keyHeader), ...array_map(
            static fn (array $r): int => AnsiUtils::visibleWidth($r[0]),
            $rows,
        ));
        $actW = max(AnsiUtils::visibleWidth($actHeader), ...array_map(
            static fn (array $r): int => AnsiUtils::visibleWidth($r[1]),
            $rows,
        ));
        $descW = 0;
        if ($hasDesc) {
            $descW = max(AnsiUtils::visibleWidth($descHeader), ...array_map(
                static fn (array $r): int => AnsiUtils::visibleWidth($r[2]),
                $rows,
            ));
        }

        $keyW = min($keyW, self::MAX_KEY_WIDTH);
        $actW = min($actW, self::MAX_ACTION_WIDTH);
        if ($hasDesc) {
            $descW = min($descW, self::MAX_DESC_WIDTH);
        }

        // Fit table chrome + columns into the live terminal width.
        // Fixed chrome: indent(2) + borders/padding: 2-col → 7, 3-col → 10.
        $chrome = self::INDENT + ($hasDesc ? 10 : 7);
        $available = max(1, $width - $chrome);
        $needed = $keyW + $actW + ($hasDesc ? $descW : 0);
        if ($needed > $available) {
            $scale = $available / max(1, $needed);
            $keyW = max(1, (int) floor($keyW * $scale));
            $actW = max(1, (int) floor($actW * $scale));
            if ($hasDesc) {
                $descW = max(1, $available - $keyW - $actW);
            } else {
                $actW = max(1, $available - $keyW);
            }
        }

        $result = [];
        $h = '─';
        $indent = str_repeat(' ', self::INDENT);

        if ($hasDesc) {
            $result[] = $this->fitLine($indent.$this->applyElement(
                'border',
                '┌'.$h.$h.str_repeat($h, $keyW).'┬'.str_repeat($h, $actW + 2).'┬'.str_repeat($h, $descW + 2).'┐',
            ), $width);
            $result[] = $this->fitLine(
                $indent
                .$this->applyElement('border', '│').' '
                .$this->applyElement('header', $this->pad($keyHeader, $keyW)).' '
                .$this->applyElement('border', '│').' '
                .$this->applyElement('header', $this->pad($actHeader, $actW)).' '
                .$this->applyElement('border', '│').' '
                .$this->applyElement('header', $this->pad($descHeader, $descW)).' '
                .$this->applyElement('border', '│'),
                $width,
            );
            $result[] = $this->fitLine($indent.$this->applyElement(
                'border',
                '├'.$h.$h.str_repeat($h, $keyW).'┼'.str_repeat($h, $actW + 2).'┼'.str_repeat($h, $descW + 2).'┤',
            ), $width);

            foreach ($rows as [$k, $a, $d]) {
                $result[] = $this->fitLine(
                    $indent
                    .$this->applyElement('border', '│').' '
                    .$this->applyElement('key', $this->truncPad($k, $keyW)).' '
                    .$this->applyElement('border', '│').' '
                    .$this->truncPad($a, $actW).' '
                    .$this->applyElement('border', '│').' '
                    .$this->applyElement('description', $this->truncPad($d, $descW)).' '
                    .$this->applyElement('border', '│'),
                    $width,
                );
            }

            $result[] = $this->fitLine($indent.$this->applyElement(
                'border',
                '└'.$h.$h.str_repeat($h, $keyW).'┴'.str_repeat($h, $actW + 2).'┴'.str_repeat($h, $descW + 2).'┘',
            ), $width);
        } else {
            $result[] = $this->fitLine($indent.$this->applyElement(
                'border',
                '┌'.$h.$h.str_repeat($h, $keyW).'┬'.str_repeat($h, $actW + 2).'┐',
            ), $width);
            $result[] = $this->fitLine(
                $indent
                .$this->applyElement('border', '│').' '
                .$this->applyElement('header', $this->pad($keyHeader, $keyW)).' '
                .$this->applyElement('border', '│').' '
                .$this->applyElement('header', $this->pad($actHeader, $actW)).' '
                .$this->applyElement('border', '│'),
                $width,
            );
            $result[] = $this->fitLine($indent.$this->applyElement(
                'border',
                '├'.$h.$h.str_repeat($h, $keyW).'┼'.str_repeat($h, $actW + 2).'┤',
            ), $width);

            foreach ($rows as [$k, $a]) {
                $result[] = $this->fitLine(
                    $indent
                    .$this->applyElement('border', '│').' '
                    .$this->applyElement('key', $this->truncPad($k, $keyW)).' '
                    .$this->applyElement('border', '│').' '
                    .$this->truncPad($a, $actW).' '
                    .$this->applyElement('border', '│'),
                    $width,
                );
            }

            $result[] = $this->fitLine($indent.$this->applyElement(
                'border',
                '└'.$h.$h.str_repeat($h, $keyW).'┴'.str_repeat($h, $actW + 2).'┘',
            ), $width);
        }

        return $result;
    }

    private function pad(string $text, int $targetWidth): string
    {
        return AnsiUtils::truncateToWidth($text, max(0, $targetWidth), ellipsis: '', pad: true);
    }

    private function truncPad(string $text, int $targetWidth): string
    {
        return AnsiUtils::truncateToWidth($text, max(0, $targetWidth), ellipsis: '…', pad: true);
    }

    private function fitLine(string $line, int $width): string
    {
        if (str_contains($line, "\n") || str_contains($line, "\r")) {
            $line = str_replace(["\r\n", "\r", "\n"], ' ', $line);
        }

        if (AnsiUtils::visibleWidth($line) <= $width) {
            return $line;
        }

        return AnsiUtils::truncateToWidth($line, $width, ellipsis: '…');
    }
}
