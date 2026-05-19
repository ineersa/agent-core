<?php

declare(strict_types=1);

namespace Ineersa\Tui\Widget;

/**
 * Placement for extension widgets relative to the prompt editor.
 */
enum WidgetPlacementEnum: string
{
    /** Render above the editor, below the status panel. */
    case AboveEditor = 'above_editor';

    /** Render below the editor, above the footer. */
    case BelowEditor = 'below_editor';
}
