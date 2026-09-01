<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command\Hotkey;

use Ineersa\Tui\Command\CommandResult;

/**
 * Carries the raw hotkey binding data from {@see SlashCommandRegistry}
 * to {@see \Ineersa\Tui\Listener\SubmitListener}.
 *
 * This is a data-transfer CommandResult — it contains no rendering logic
 * and stays in the TuiCommand layer so SlashCommandRegistry has zero
 * theme dependencies.
 *
 * SubmitListener stores plain structured groups on a System transcript
 * block; {@see \Ineersa\Tui\Transcript\HotkeyTableWidget} renders them.
 * The empty-message fallback is owned by the widget, not duplicated here.
 */
final readonly class HotkeyTableData implements CommandResult
{
    /**
     * @param array<string, list<HotkeyBindingDTO>> $groups       grouped bindings by context
     * @param string                                $emptyMessage optional override for HotkeyTableWidget empty-state copy
     */
    public function __construct(
        public array $groups,
        public string $emptyMessage = '',
    ) {
    }
}
