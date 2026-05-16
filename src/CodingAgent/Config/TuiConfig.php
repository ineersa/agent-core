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
     * @param string       $theme      Selected theme name from resolved Hatfield config
     * @param list<string> $themePaths Theme search directories ordered by priority
     */
    public function __construct(
        public string $theme,
        public array $themePaths = [],
    ) {
    }

    /**
     * Create from a raw config array (from merged Hatfield YAML).
     *
     * The theme must be present in the merged config — it always comes
     * from {@see config/hatfield.defaults.yaml} (or a user override).
     *
     * @param array<string, mixed> $data
     *
     * @throws \RuntimeException if the theme key is missing or empty
     */
    public static function fromArray(array $data): self
    {
        $theme = $data['theme'] ?? null;
        if (!\is_string($theme) || '' === $theme) {
            throw new \RuntimeException('TUI theme not configured. Set tui.theme in config/hatfield.defaults.yaml or your Hatfield settings.');
        }

        return new self(
            theme: $theme,
            themePaths: (array) ($data['theme_paths'] ?? []),
        );
    }
}
