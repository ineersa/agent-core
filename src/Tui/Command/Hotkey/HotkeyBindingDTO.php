<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command\Hotkey;

/**
 * Display-only hotkey hint for the /hotkeys catalog.
 *
 * This is metadata for documentation and user-facing help tables.
 * It does NOT route or execute input — hotkey execution stays with
 * the existing Symfony TUI keybinding engine, listener pipeline,
 * and widget dispatch.
 *
 * For editor-level hotkeys, the human-readable {@see $action} and
 * {@see $description} are mapped from the technical action names
 * used internally by Symfony TUI's {@see EditorWidget} keybinding
 * system.
 */
final readonly class HotkeyBindingDTO
{
    /**
     * @param string       $context     Grouping context: 'Global', 'Editor', 'Completion', 'History', 'Model'
     * @param list<string> $keys        Key identifiers, e.g. ['ctrl+j', 'shift+enter']; at least one required
     * @param string       $action      Short human label, e.g. 'Insert newline'
     * @param string       $description Optional longer description; empty string for none
     * @param string       $source      Origin marker: 'core' for built-in, 'theme' for theme-defined, extension ID for future extension contributions
     * @param int          $priority    Sort order within context (lower = earlier); default 50
     */
    public function __construct(
        public string $context,
        public array $keys,
        public string $action,
        public string $description = '',
        public string $source = 'core',
        public int $priority = 50,
    ) {
    }
}
