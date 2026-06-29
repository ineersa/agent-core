<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

/**
 * Live/session-only mutable display state for the transcript.
 *
 * Initialized from TranscriptDisplayConfig at TUI startup. Changes
 * (e.g. Ctrl+O toggling preview expansion) affect this state only
 * and are NOT persisted to Hatfield settings or session metadata.
 *
 * No thinking visibility state in v1 — the renderer reads
 * TranscriptDisplayConfig::thinkingVisible directly.
 */
final class TranscriptDisplayState
{
    /**
     * @param bool $previewableBlocksExpanded Whether previewable blocks (tool results, diffs)
     *                                        are currently expanded in the transcript.
     *                                        Toggled by Ctrl+O; initialized from config.
     */
    public function __construct(
        public bool $previewableBlocksExpanded = false,
    ) {
    }
}
