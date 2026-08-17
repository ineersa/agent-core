<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

/**
 * Narrow contract for mutable semantic transcript widgets.
 *
 * Each implementer binds exactly one {@see TranscriptVisualNode} kind and
 * mutates its mounted content in place via {@see apply()}. Mounted binding
 * (see {@see TranscriptMountedWidget}) asks {@see canBind()} before applying:
 * a wrong-kind node on an existing stable key must re-create the widget
 * instead of binding incompatible data onto the old one.
 *
 * Implemented only by the semantic widgets that own mutable content
 * (markdown, tool exchange, question, subagent). Immutable widgets
 * (welcome, turn separator) and the generic native TextWidget path stay
 * explicit in the mounted adapter.
 */
interface MutableTranscriptWidget
{
    /**
     * Whether this widget can bind the given visual node in place.
     *
     * Implementations accept exactly their own visual kind.
     */
    public function canBind(TranscriptVisualNode $node): bool;

    /**
     * Bind the given visual node data in place.
     *
     * Must only be called when {@see canBind()} returned true.
     */
    public function apply(TranscriptVisualNode $node): void;
}
