<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Config\TuiTranscriptConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;

/**
 * Maps the Hatfield config DTO (src/CodingAgent/Config/) to the TUI-local
 * immutable display config (src/Tui/Transcript/).
 *
 * Lives at the TUI application boundary so that src/Tui/Transcript/ remains
 * independent from CodingAgent\Config.
 */
final readonly class TranscriptDisplayConfigMapper
{
    public function map(TuiTranscriptConfig $config): TranscriptDisplayConfig
    {
        return new TranscriptDisplayConfig(
            thinkingVisible: $config->thinking->visible,
            thinkingStyle: $config->thinking->style,
            previewsExpandedByDefault: $config->previews->expandedByDefault,
            toolResultPreviewLines: $config->previews->toolResultLines,
            diffPreviewLines: $config->previews->diffLines,
        );
    }
}
