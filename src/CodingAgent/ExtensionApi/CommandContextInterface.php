<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * UI-agnostic context passed to extension command handlers.
 *
 * Exposes a notify() method that surfaces a message to the user
 * through the TUI transcript (mirrors pi's cmdCtx.ui.notify).
 *
 * This is the public contract — the actual notification mechanism
 * is provided by the TUI adapter.
 */
interface CommandContextInterface
{
    /**
     * Display a notification message to the user.
     *
     * @param string $message The message text
     * @param string $level   Notification level: 'info', 'success', 'warning', 'error'
     */
    public function notify(string $message, string $level = 'info'): void;
}
