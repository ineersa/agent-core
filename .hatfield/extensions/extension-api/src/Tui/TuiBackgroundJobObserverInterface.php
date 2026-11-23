<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiBackgroundJobObservationDTO;

interface TuiBackgroundJobObserverInterface
{
    public function onTuiBackgroundJobObserved(TuiBackgroundJobObservationDTO $observation): void;
}
