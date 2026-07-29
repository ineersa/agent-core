<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

/**
 * Public semantic colors for extension-owned TUI widgets.
 *
 * Hosts map these onto the active theme palette. Extensions must not hardcode
 * hex/ANSI values or depend on Hatfield-internal theme tokens.
 */
enum TuiSemanticColorEnum: string
{
    case Text = 'text';
    case Muted = 'muted';
    case Accent = 'accent';
    case Success = 'success';
    case Warning = 'warning';
    case Error = 'error';
}
