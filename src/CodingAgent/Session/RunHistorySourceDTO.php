<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

/** Canonical log and resolved parent/child path, not reconstructed history. */
final readonly class RunHistorySourceDTO
{
    public function __construct(public JsonlRunEventLog $log, public string $path)
    {
    }
}
