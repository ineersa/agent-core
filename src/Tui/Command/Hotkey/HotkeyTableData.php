<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command\Hotkey;

use Ineersa\Tui\Command\CommandResult;
use Ineersa\Tui\Listener\SubmitListener;
use Ineersa\Tui\Transcript\HotkeyTableWidget;

/**
 * Carries the raw hotkey binding data from {@see SlashCommandRegistry}
 * to {@see SubmitListener}.
 *
 * This is a data-transfer CommandResult — it contains no rendering logic
 * and stays in the TuiCommand layer so SlashCommandRegistry has zero
 * theme dependencies.
 *
 * SubmitListener stores typed binding groups on a System transcript
 * block; {@see HotkeyTableWidget} renders them.
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
