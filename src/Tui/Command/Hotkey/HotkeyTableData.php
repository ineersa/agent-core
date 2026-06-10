<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command\Hotkey;

use Ineersa\Tui\Command\CommandResult;

/**
 * Carries the raw hotkey binding data from {@see SlashCommandRegistry}
 * to a theme-aware renderer (e.g. {@see SubmitListener}).
 *
 * This is a data-transfer CommandResult — it contains no rendering logic
 * and stays in the TuiCommand layer so SlashCommandRegistry has zero
 * theme dependencies.
 *
 * The actual themed table rendering happens in TuiListener layer where
 * theme access is allowed by deptrac.
 */
final readonly class HotkeyTableData implements CommandResult
{
    /**
     * @param array<string, list<HotkeyBindingDTO>> $groups       grouped bindings by context
     * @param string                                $emptyMessage shown when the registry is empty
     */
    public function __construct(
        public array $groups,
        public string $emptyMessage = 'No hotkey hints registered. This is a bug — hotkeys should be populated during TUI startup.',
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->groups;
    }
}
