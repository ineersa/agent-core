<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

/** Observes final graceful interactive quit for an established selected session. */
interface TuiSessionCloseObserverInterface
{
    public function onTuiSessionClosing(string $sessionId): void;
}
