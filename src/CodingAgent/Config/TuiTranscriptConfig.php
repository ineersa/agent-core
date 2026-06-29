<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Hatfield config DTO for tui.transcript.* settings.
 *
 * Nested structure matching YAML config hierarchy:
 *   tui.transcript.thinking.visible
 *   tui.transcript.thinking.style
 *   tui.transcript.previews.expanded_by_default
 *   tui.transcript.previews.tool_result_lines
 *   tui.transcript.previews.diff_lines
 *
 * Hydrated from the tui.transcript section of Hatfield merged config via
 * Symfony Serializer in AppConfig::fromContainer().
 *
 * @see config/hatfield.defaults.yaml
 */
final readonly class TuiTranscriptThinkingConfig
{
    /**
     * @param bool   $visible Whether assistant thinking content is visible in the transcript
     * @param string $style   Visual style for visible thinking (e.g. 'dim_italic')
     */
    public function __construct(
        public bool $visible = true,
        public string $style = 'dim_italic',
    ) {
    }
}

/**
 * Hatfield config DTO for tui.transcript.previews.* settings.
 */
final readonly class TuiTranscriptPreviewsConfig
{
    /**
     * @param bool $expandedByDefault Whether previewable blocks start expanded
     * @param int  $toolResultLines   Max lines shown for normal tool result previews
     * @param int  $diffLines         Max lines shown for diff-rendered tool result previews
     */
    public function __construct(
        #[SerializedName('expanded_by_default')]
        public bool $expandedByDefault = false,
        #[SerializedName('tool_result_lines')]
        public int $toolResultLines = 8,
        #[SerializedName('diff_lines')]
        public int $diffLines = 20,
    ) {
    }
}

/**
 * Hatfield config DTO for the complete tui.transcript section.
 *
 * Tied to the YAML structure:
 *   tui:
 *     transcript:
 *       thinking:
 *         visible: true
 *         style: dim_italic
 *       previews:
 *         expanded_by_default: false
 *         tool_result_lines: 8
 *         diff_lines: 20
 */
final readonly class TuiTranscriptConfig
{
    /**
     * @param TuiTranscriptThinkingConfig $thinking Thinking visibility and style settings
     * @param TuiTranscriptPreviewsConfig $previews Preview (tool output/diff) display settings
     */
    public function __construct(
        public TuiTranscriptThinkingConfig $thinking = new TuiTranscriptThinkingConfig(),
        public TuiTranscriptPreviewsConfig $previews = new TuiTranscriptPreviewsConfig(),
    ) {
    }
}
