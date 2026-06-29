<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Hatfield config DTO for tui.transcript.thinking.* settings.
 *
 * Nested structure matching YAML config hierarchy:
 *   tui.transcript.thinking.visible
 *   tui.transcript.thinking.style
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
