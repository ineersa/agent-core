<?php

declare(strict_types=1);

namespace Ineersa\Tui\Command;

/**
 * Result of executing a command — discriminated union of possible outcomes.
 *
 * Each variant represents a different side effect or response to a command.
 * Implementations are immutable value objects.
 */
interface CommandResult
{
}
