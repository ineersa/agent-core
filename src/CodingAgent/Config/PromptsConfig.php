<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Top-level Hatfield `prompts: []` settings.
 *
 * Holds a list of paths (files or directories) specified in the user's
 * Hatfield settings YAML. These paths are in addition to the built-in
 * auto-discovery directories (~/.hatfield/prompts/ and <cwd>/.hatfield/prompts/).
 *
 * Path resolution (tilde, %kernel.project_dir%, relative paths) is handled
 * by the declarative PATH_CONFIG entry in SettingsResolver, not here.
 */
final readonly class PromptsConfig
{
    /** @param list<string> $paths */
    public function __construct(
        public array $paths = [],
    ) {
    }

    /**
     * Build from raw config data (e.g. a YAML-parsed array).
     *
     * Non-array input and non-string / blank string entries are silently
     * ignored (the prompt-template loader treats missing paths as diagnostics).
     */
    public static function fromRaw(mixed $raw): self
    {
        if (!\is_array($raw)) {
            return new self();
        }

        $paths = [];
        foreach ($raw as $value) {
            if (\is_string($value) && '' !== trim($value)) {
                $paths[] = $value;
            }
        }

        return new self($paths);
    }

    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->prompts;
    }
}
