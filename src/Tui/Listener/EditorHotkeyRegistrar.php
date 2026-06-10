<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\Hotkey\HotkeyBindingDTO;
use Ineersa\Tui\Command\Hotkey\HotkeyRegistry;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers editor-level hotkey hints derived from the active
 * {@see PromptEditor} keybindings.
 *
 * Reads the live EditorWidget keybinding map and translates
 * technical action names into human-friendly labels for /hotkeys.
 *
 * Because widget-level keybindings may override or extend the
 * Symfony defaults, this registrar reads the ACTIVE bindings
 * rather than duplicating a stale default table.
 */
final readonly class EditorHotkeyRegistrar implements TuiListenerRegistrar
{
    /**
     * Maps internal EditorWidget action names to human-readable labels.
     *
     * Only the actions relevant to a coding-agent prompt are shown;
     * rarely-used clipboard/undo actions are omitted to keep /hotkeys
     * concise.
     *
     * @var array<string, array{action: string, description: string, priority: int}>
     */
    private const ACTION_LABELS = [
        'submit' => [
            'action' => 'Submit prompt',
            'description' => 'Send the current editor text to the agent',
            'priority' => 10,
        ],
        'new_line' => [
            'action' => 'Insert newline',
            'description' => 'Start a new line in the multiline editor',
            'priority' => 20,
        ],
        'select_cancel' => [
            'action' => 'Cancel / clear editor',
            'description' => 'Dismiss completion, clear input, or cancel current mode',
            'priority' => 30,
        ],
        'cursor_up' => [
            'action' => 'Move cursor up',
            'description' => 'Navigate to the previous line',
            'priority' => 40,
        ],
        'cursor_down' => [
            'action' => 'Move cursor down',
            'description' => 'Navigate to the next line',
            'priority' => 50,
        ],
        'cursor_left' => [
            'action' => 'Move cursor left',
            'description' => 'Move one character backward',
            'priority' => 60,
        ],
        'cursor_right' => [
            'action' => 'Move cursor right',
            'description' => 'Move one character forward',
            'priority' => 70,
        ],
        'cursor_word_left' => [
            'action' => 'Previous word',
            'description' => 'Jump to the start of the previous word',
            'priority' => 80,
        ],
        'cursor_word_right' => [
            'action' => 'Next word',
            'description' => 'Jump to the start of the next word',
            'priority' => 90,
        ],
        'cursor_line_start' => [
            'action' => 'Go to line start',
            'description' => 'Move cursor to the beginning of the current line',
            'priority' => 100,
        ],
        'cursor_line_end' => [
            'action' => 'Go to line end',
            'description' => 'Move cursor to the end of the current line',
            'priority' => 110,
        ],
        'page_up' => [
            'action' => 'Page up',
            'description' => 'Scroll editor viewport up one page',
            'priority' => 120,
        ],
        'page_down' => [
            'action' => 'Page down',
            'description' => 'Scroll editor viewport down one page',
            'priority' => 130,
        ],
        'delete_char_backward' => [
            'action' => 'Delete char backward',
            'description' => 'Remove the character before the cursor',
            'priority' => 140,
        ],
        'delete_char_forward' => [
            'action' => 'Delete char forward',
            'description' => 'Remove the character after the cursor',
            'priority' => 150,
        ],
        'delete_word_backward' => [
            'action' => 'Delete word backward',
            'description' => 'Remove the word before the cursor',
            'priority' => 160,
        ],
        'delete_word_forward' => [
            'action' => 'Delete word forward',
            'description' => 'Remove the word after the cursor',
            'priority' => 170,
        ],
        'delete_to_line_start' => [
            'action' => 'Delete to line start',
            'description' => 'Remove text from cursor to beginning of line',
            'priority' => 180,
        ],
        'delete_to_line_end' => [
            'action' => 'Delete to line end',
            'description' => 'Remove text from cursor to end of line',
            'priority' => 190,
        ],
        'delete_line' => [
            'action' => 'Delete line',
            'description' => 'Remove the entire current line',
            'priority' => 200,
        ],
    ];

    /**
     * @param PromptEditor   $promptEditor   The active editor facade
     * @param HotkeyRegistry $hotkeyRegistry Hotkey catalog to populate
     */
    public function __construct(
        private PromptEditor $promptEditor,
        private HotkeyRegistry $hotkeyRegistry,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $kb = $this->promptEditor->getWidget()->getKeybindings();

        foreach (self::ACTION_LABELS as $actionName => $label) {
            $keys = $kb->getBindings($actionName);
            if ([] === $keys) {
                continue;
            }

            $this->hotkeyRegistry->add(new HotkeyBindingDTO(
                context: 'Editor',
                keys: $keys,
                action: $label['action'],
                description: $label['description'],
                source: 'core',
                priority: $label['priority'],
            ));
        }
    }
}
