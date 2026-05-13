<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * TUI settings resolved from Hatfield config.
 *
 * Immutable value object. Contains the selected theme name and
 * theme search paths ordered by priority (first wins for loading).
 */
final readonly class TuiConfig
{
    /**
     * @param string       $theme      Selected theme name (e.g. "cyberpunk")
     * @param list<string> $themePaths Theme search directories ordered by priority
     */
    public function __construct(
        public string $theme = 'cyberpunk',
        public array $themePaths = [],
    ) {
    }

    /**
     * Create from a raw config array (from merged Hatfield YAML).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            theme: (string) ($data['theme'] ?? 'cyberpunk'),
            themePaths: (array) ($data['theme_paths'] ?? []),
        );
    }
}
