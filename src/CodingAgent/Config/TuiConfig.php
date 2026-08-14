<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * TUI settings resolved from Hatfield config.
 *
 * Immutable value object. Contains the selected theme name, theme search
 * paths ordered by priority (first wins for loading), and transcript display
 * config (thinking visibility/style, preview expansion defaults).
 *
 * Hydrated from the tui section of Hatfield merged config via
 * Symfony Serializer in {@see AppConfig::fromContainer()}.
 */
final readonly class TuiConfig
{
    /**
     * @param string              $theme      Selected theme name from resolved Hatfield config
     * @param list<string>        $themePaths Theme search directories ordered by priority
     * @param TuiTranscriptConfig $transcript Transcript display settings (thinking, previews)
     */
    public function __construct(
        public string $theme,
        public array $themePaths = [],
        public TuiTranscriptConfig $transcript = new TuiTranscriptConfig(),
    ) {
    }
}
