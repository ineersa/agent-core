<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\Hotkey\HotkeyBindingDTO;
use Ineersa\Tui\Command\Hotkey\HotkeyRegistry;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * Registers application-level hotkey hints (global shortcuts,
 * completion keys, prompt history navigation, model controls).
 *
 * These hotkeys are enforced by the TUI listener pipeline
 * ({@see CtrlCInputInterceptor}, {@see CompletionListener},
 * {@see PromptHistoryListener}, {@see ModelControlListener})
 * rather than by the EditorWidget keybinding engine, so their
 * descriptions are maintained here rather than derived from
 * live widget state.
 */
final readonly class AppHotkeyRegistrar implements TuiListenerRegistrar
{
    public function __construct(
        private HotkeyRegistry $hotkeyRegistry,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        // ── Global shortcuts ──────────────────────────────────────
        $this->hotkeyRegistry->add(new HotkeyBindingDTO(
            context: 'Global',
            keys: ['ctrl+c'],
            action: 'Clear editor / cancel',
            description: 'Single press clears the editor; double press within 1.5s exits the TUI',
            source: 'core',
            priority: 10,
        ));

        $this->hotkeyRegistry->add(new HotkeyBindingDTO(
            context: 'Global',
            keys: ['ctrl+d'],
            action: 'Exit TUI',
            description: 'Exit the application cleanly when the editor is empty',
            source: 'core',
            priority: 20,
        ));

        // ── Prompt history ────────────────────────────────────────
        $this->hotkeyRegistry->add(new HotkeyBindingDTO(
            context: 'History',
            keys: ['up'],
            action: 'Previous prompt',
            description: 'Recall the previously submitted prompt when editor is empty',
            source: 'core',
            priority: 10,
        ));

        $this->hotkeyRegistry->add(new HotkeyBindingDTO(
            context: 'History',
            keys: ['down'],
            action: 'Next prompt',
            description: 'Move forward through prompt history when editor is empty',
            source: 'core',
            priority: 20,
        ));

        // ── Completion ────────────────────────────────────────────
        $this->hotkeyRegistry->add(new HotkeyBindingDTO(
            context: 'Completion',
            keys: ['tab'],
            action: 'Trigger / accept completion',
            description: 'Open completion suggestions or accept the highlighted item',
            source: 'core',
            priority: 10,
        ));

        $this->hotkeyRegistry->add(new HotkeyBindingDTO(
            context: 'Completion',
            keys: ['escape'],
            action: 'Close completion',
            description: 'Dismiss the completion menu or cancel current editor mode',
            source: 'core',
            priority: 20,
        ));

        $this->hotkeyRegistry->add(new HotkeyBindingDTO(
            context: 'Completion',
            keys: ['enter'],
            action: 'Accept and submit',
            description: 'Accept the highlighted completion and submit the command',
            source: 'core',
            priority: 30,
        ));

        $this->hotkeyRegistry->add(new HotkeyBindingDTO(
            context: 'Completion',
            keys: ['up', 'down'],
            action: 'Navigate suggestions',
            description: 'Move the selection highlight up or down in the completion menu',
            source: 'core',
            priority: 40,
        ));

        // ── Model controls ────────────────────────────────────────
        $this->hotkeyRegistry->add(new HotkeyBindingDTO(
            context: 'Model',
            keys: ['ctrl+p'],
            action: 'Cycle model',
            description: 'Switch to the next available model',
            source: 'core',
            priority: 10,
        ));

        $this->hotkeyRegistry->add(new HotkeyBindingDTO(
            context: 'Model',
            keys: ['shift+tab'],
            action: 'Cycle reasoning level',
            description: 'Switch to the next reasoning / thinking level',
            source: 'core',
            priority: 20,
        ));
    }
}
