<?php

declare(strict_types=1);

namespace Ineersa\Tui\Editor;

use Symfony\Component\Tui\Input\Key;
use Symfony\Component\Tui\Input\Keybindings;

/**
 * Editor-level action bindings for app shortcuts handled outside EditorWidget.
 *
 * Applied through {@see PromptEditor::setKeybindings()} so listeners match
 * via the mounted editor's context-shared Symfony KeyParser (legacy + Kitty).
 */
final class AppShortcutKeybindings
{
    /**
     * @return array<string, list<string>>
     */
    public static function bindings(): array
    {
        return [
            // Preserve EditorWidget default Shift+Enter and add portable Ctrl+J.
            'new_line' => ['ctrl+j', 'shift+enter'],
            'toggle_preview_expansion' => ['ctrl+o'],
            'toggle_loaded_resources' => ['ctrl+r'],
            'toggle_subagent_live' => [Key::ctrl('\\')],
            'cycle_favorite_model' => ['ctrl+p'],
            'cycle_reasoning' => ['shift+tab'],
            'trigger_completion' => ['tab'],
        ];
    }

    public static function create(): Keybindings
    {
        return new Keybindings(self::bindings());
    }
}
