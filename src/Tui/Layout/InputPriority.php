<?php

declare(strict_types=1);

namespace Ineersa\Tui\Layout;

/**
 * Named ownership for TUI InputEvent listener priorities.
 *
 * Higher priority listeners run earlier (Symfony EventDispatcher
 * semantics). These values are the existing production roles — this
 * class only gives them one owner so registration sites stop
 * scattering magic numbers.
 */
final class InputPriority
{
    /** Completion overlay teardown on Ctrl+C/D — runs before global interrupt so the overlay closes cleanly. */
    public const COMPLETION_PREFLIGHT = 105;

    /** Global interrupt: Ctrl+C cancel / double-press quit, Ctrl+D quit. */
    public const GLOBAL_INTERRUPT = 100;

    /** Ctrl+O preview expansion toggle. */
    public const PREVIEW_EXPANSION = 98;

    /** Standalone Ctrl+V image clipboard paste. */
    public const IMAGE_PASTE = 96;

    /** Ctrl+P model cycling and Shift+Tab reasoning cycling. */
    public const MODEL_CONTROL = 95;

    /** Completion overlay routing (Tab/Enter/Escape/Up/Down) and Ctrl+\ subagent live view toggle. */
    public const COMPLETION_SUBAGENT = 90;

    /** Extension slot input handlers and other default-tier listeners (e.g. Ctrl+R loaded-resources toggle). */
    public const EXTENSION_DEFAULT = 50;

    private function __construct()
    {
    }
}
