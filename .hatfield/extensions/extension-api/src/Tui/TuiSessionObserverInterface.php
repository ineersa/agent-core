<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

/** Observes selection of an established session in the terminal-owning process. */
interface TuiSessionObserverInterface
{
    /** Called on new/resumed sessions and draft promotion. Do not block the UI. */
    public function onTuiSessionSelected(string $sessionId): void;
}
