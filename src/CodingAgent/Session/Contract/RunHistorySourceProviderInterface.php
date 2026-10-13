<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\Contract;

use Ineersa\CodingAgent\Session\RunHistorySourceDTO;

/** Resolve canonical indexed history without exposing artifact routing to replay. */
interface RunHistorySourceProviderInterface
{
    public function historySource(string $runId): RunHistorySourceDTO;
}
