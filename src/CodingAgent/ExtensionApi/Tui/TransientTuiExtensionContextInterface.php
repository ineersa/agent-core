<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

use Symfony\Component\Tui\Style\Style;
use Symfony\Component\Tui\Widget\AbstractWidget;

/**
 * Optional richer TUI surface for temporary extension-owned widgets.
 *
 * Hosts that do not implement this interface remain compatible via
 * {@see TuiExtensionContextInterface} alone. Current Hatfield always provides
 * the richer bridge.
 */
interface TransientTuiExtensionContextInterface extends TuiExtensionContextInterface
{
    /**
     * Show one temporary extension widget immediately above the editor.
     *
     * Hosts keep at most one transient widget: a later call replaces the prior.
     * The widget is not part of the canonical transcript and is cleared on the
     * next meaningful conversation transition.
     */
    public function showTransientWidget(AbstractWidget $widget): void;

    /**
     * Build a native Symfony Style from the active theme semantic palette.
     */
    public function createTextStyle(
        TuiSemanticColorEnum $color = TuiSemanticColorEnum::Text,
        bool $dim = false,
        bool $italic = false,
    ): Style;
}
