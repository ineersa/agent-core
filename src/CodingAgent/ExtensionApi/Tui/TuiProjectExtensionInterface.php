<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

/**
 * Optional TUI wiring hook for project extensions.
 *
 * The host passes an opaque runtime context object (interactive TUI only).
 * Extensions cast it to the host TUI context type inside registerTui().
 */
interface TuiProjectExtensionInterface
{
    /**
     * @param object $tuiRuntimeContext host TUI per-session context (e.g. TuiRuntimeContext)
     */
    public function registerTui(object $tuiRuntimeContext): void;
}
