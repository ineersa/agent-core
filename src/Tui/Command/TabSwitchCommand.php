<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * POC: Result of executing a /tab command to switch the active tab.
 *
 * Carries the requested new active tab index.
 * The TabRoutingListener applies the switch.
 */
final readonly class TabSwitchCommand implements CommandResult
{
    public function __construct(
        public int $newIndex,
    ) {
    }
}
