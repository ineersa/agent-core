<?php

declare(strict_types=1);

namespace Ineersa\Tui\Terminal;

use Symfony\Component\Tui\Tui;

/**
 * Diagnostic prototype that bypasses differential rendering entirely.
 */
final class FullRepaintTui extends Tui
{
    public function requestRender(bool $force = false): void
    {
        parent::requestRender(true);
    }
}
