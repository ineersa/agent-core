<?php

declare(strict_types=1);

namespace Ineersa\Tui\Runtime;

/**
 * POC: lightweight tab state registry.
 *
 * Holds multiple tab definitions (each backed by a TuiSessionState) and tracks
 * the active tab index. This is the core data structure enabling multi-run TUI.
 *
 * This is POC/prototype code and will be replaced once the
 * Symfony TUI TabsWidget PR is merged upstream.
 *
 * @see https://github.com/symfony/symfony/pull/64132
 */
final class TabService
{
    /** @var list<TabDefinition> */
    private array $tabs = [];

    private int $activeIndex = 0;

    public function addTab(TabDefinition $tab): void
    {
        $this->tabs[] = $tab;
    }

    public function removeTab(int $index): void
    {
        if (isset($this->tabs[$index])) {
            array_splice($this->tabs, $index, 1);
            if ($this->activeIndex >= \count($this->tabs)) {
                $this->activeIndex = max(0, \count($this->tabs) - 1);
            }
        }
    }

    public function switchTo(int $index): void
    {
        if ($index >= 0 && $index < \count($this->tabs)) {
            $this->activeIndex = $index;
        }
    }

    public function activeIndex(): int
    {
        return $this->activeIndex;
    }

    public function active(): ?TabDefinition
    {
        return $this->tabs[$this->activeIndex] ?? null;
    }

    public function activeState(): ?TuiSessionState
    {
        $tab = $this->active();

        return $tab?->state;
    }

    /** @return list<TabDefinition> */
    public function tabs(): array
    {
        return $this->tabs;
    }

    public function count(): int
    {
        return \count($this->tabs);
    }

    public function tabAt(int $index): ?TabDefinition
    {
        return $this->tabs[$index] ?? null;
    }

    /**
     * Find a tab by its identifier.
     */
    public function findTabById(string $id): ?TabDefinition
    {
        foreach ($this->tabs as $tab) {
            if ($tab->id === $id) {
                return $tab;
            }
        }

        return null;
    }
}
