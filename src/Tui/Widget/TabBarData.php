<?php

declare(strict_types=1);

namespace Ineersa\Tui\Widget;

/**
 * POC: display data for the tab bar widget.
 *
 * Carries tab labels and the active index so TabBarWidget can render
 * without depending on TuiRuntime\TabService, preserving the deptrac
 * boundary (TuiWidget must not depend on TuiRuntime).
 *
 * @see https://github.com/symfony/symfony/pull/64132
 */
final class TabBarData
{
    /**
     * @param list<array{id: string, label: string}> $tabs
     */
    public function __construct(
        public readonly array $tabs,
        public readonly int $activeIndex,
    ) {
    }

    public function count(): int
    {
        return \count($this->tabs);
    }
}
