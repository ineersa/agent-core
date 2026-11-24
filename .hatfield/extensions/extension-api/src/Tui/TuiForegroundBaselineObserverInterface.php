<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiForegroundBaselineDTO;

/** Optional reconciliation after validated attachment, before following live observations. */
interface TuiForegroundBaselineObserverInterface
{
    /** Does not replay terminal outcomes. Observers must not block the UI. */
    public function onTuiForegroundBaseline(TuiForegroundBaselineDTO $baseline): void;
}
