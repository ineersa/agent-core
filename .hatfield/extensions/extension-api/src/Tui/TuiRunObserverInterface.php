<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiRunObservationDTO;

/** Live foreground observations in the interactive process; not a history replay. */
interface TuiRunObserverInterface
{
    /** Receives only identities and transitions. Observers must not block the UI. */
    public function onTuiRunObserved(TuiRunObservationDTO $observation): void;
}
