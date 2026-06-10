<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command\Hotkey;

/**
 * Display-only catalog of active hotkey hints.
 *
 * Populated during TUI startup by registrars and optionally by
 * tagged hotkey providers. The registry does NOT execute hotkeys —
 * it is purely metadata for /hotkeys rendering, documentation,
 * and future footer/help integration.
 *
 * No dependency on Symfony TUI, EditorWidget, or any project layer
 * beyond PHP stdlib. This keeps it inside the TuiCommand layer
 * (which has zero allowed dependencies by deptrac design) so the
 * /hotkeys command can reference it inline.
 */
final class HotkeyRegistry
{
    /** @var list<HotkeyBindingDTO> */
    private array $bindings = [];

    /**
     * Add one or more hotkey hints.
     */
    public function add(HotkeyBindingDTO $binding): void
    {
        $this->bindings[] = $binding;
    }

    /**
     * Return all registered hotkey hints.
     *
     * @return list<HotkeyBindingDTO>
     */
    public function all(): array
    {
        return $this->bindings;
    }

    /**
     * Return hotkey hints grouped by context, each group sorted by priority
     * then action name.
     *
     * @return array<string, list<HotkeyBindingDTO>>
     */
    public function grouped(): array
    {
        $groups = [];
        foreach ($this->all() as $b) {
            $groups[$b->context][] = $b;
        }

        foreach ($groups as &$group) {
            usort($group, static function (HotkeyBindingDTO $a, HotkeyBindingDTO $b): int {
                $p = $a->priority <=> $b->priority;
                if (0 !== $p) {
                    return $p;
                }

                return strcmp($a->action, $b->action);
            });
        }
        unset($group);

        // Context order: Global → History → Editor → Completion → Model
        $order = ['Global' => 0, 'History' => 1, 'Editor' => 2, 'Completion' => 3, 'Model' => 4];

        uksort($groups, static function (string $a, string $b) use ($order): int {
            return ($order[$a] ?? 99) <=> ($order[$b] ?? 99);
        });

        return $groups;
    }
}
