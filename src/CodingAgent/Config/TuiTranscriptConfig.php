<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

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
 *
 * Hydrated from the tui.transcript section of Hatfield merged config via
 * Symfony Serializer in AppConfig::fromContainer().
 *
 * @see config/hatfield.defaults.yaml
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
