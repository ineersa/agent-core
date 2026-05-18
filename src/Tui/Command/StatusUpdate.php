<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Update a keyed entry in the status panel.
 */
final readonly class StatusUpdate implements CommandResult
{
    /**
     * @param string $key   The status entry key
     * @param string $value The new value for the status entry
     */
    public function __construct(
        public string $key,
        public string $value,
    ) {
    }
}
