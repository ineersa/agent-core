<?php

declare(strict_types=1);

namespace Ineersa\Tui\Transcript;

/**
 * TUI-local immutable rendering config for the transcript.
 *
 * Carries the resolved display settings for the current TUI session,
 * flattened from the Hatfield config so that src/Tui/Transcript/ does
 * not depend on src/CodingAgent/Config/.
 *
 * Projection owns canonical block facts; rendering config owns local
 * display policy.
 */
final readonly class TranscriptDisplayConfig
{
    /**
     * @param bool   $thinkingVisible           Whether assistant thinking content is visible
     * @param string $thinkingStyle             Visual style for visible thinking (e.g. 'dim_italic')
     * @param bool   $previewsExpandedByDefault Whether previewable blocks start expanded
     * @param int    $toolResultPreviewLines    Max lines for normal tool result previews
     * @param int    $diffPreviewLines          Max lines for edit-diff/write-content ToolCall payload previews
     */
    public function __construct(
        public bool $thinkingVisible = true,
        public string $thinkingStyle = 'dim_italic',
        public bool $previewsExpandedByDefault = false,
        public int $toolResultPreviewLines = 8,
        public int $diffPreviewLines = 20,
    ) {
    }
}
