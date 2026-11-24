<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

use Ineersa\Hatfield\ExtensionApi\Tui\DTO\TuiBackgroundSnapshotDTO;

interface TuiBackgroundSnapshotObserverInterface
{
    public function onTuiBackgroundSnapshot(TuiBackgroundSnapshotDTO $snapshot): void;
}
