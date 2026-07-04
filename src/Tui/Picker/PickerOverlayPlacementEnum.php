<?php

declare(strict_types=1);

namespace Ineersa\Tui\Picker;

/**
 * Where {@see PickerOverlay} inserts its container in the ChatScreen widget tree.
 */
enum PickerOverlayPlacementEnum
{
    /**
     * Below the prompt editor (completion-menu slot). Default for model/session/resume pickers.
     */
    case AfterEditor;

    /**
     * Above the prompt editor (question-overlay slot). Used by /tree and /rewind because
     * they rebuild SelectListWidget items on navigation; the below-editor band shared
     * incremental paint with footer/status and caused live stale rows and bleed.
     */
    case BeforeEditor;
}
