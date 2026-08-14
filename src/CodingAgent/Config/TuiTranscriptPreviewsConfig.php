<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Hatfield config DTO for tui.transcript.previews.* settings.
 *
 * Nested structure matching YAML config hierarchy:
 *   tui.transcript.previews.expanded_by_default
 *   tui.transcript.previews.tool_result_lines
 *   tui.transcript.previews.diff_lines
 *
 * Hydrated from the tui.transcript section of Hatfield merged config via
 * Symfony Serializer in AppConfig::fromContainer().
 */
final readonly class TuiTranscriptPreviewsConfig
{
    /**
     * @param bool $expandedByDefault Whether previewable blocks start expanded
     * @param int  $toolResultLines   Max lines shown for normal tool result previews
     * @param int  $diffLines         Max lines shown for diff-rendered tool result previews
     */
    public function __construct(
        public bool $expandedByDefault = false,
        public int $toolResultLines = 8,
        public int $diffLines = 20,
    ) {
    }
}
