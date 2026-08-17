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
 * by the declarative PATH_CONFIG entry in AppConfigLoader, not here.
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
     * Explicitly configured values are validated strictly: a malformed
     * section or entry fails configuration load. Omission (null) yields
     * the default empty list.
     */
    public static function fromRaw(mixed $raw): self
    {
        if (null === $raw) {
            return new self();
        }

        if (!\is_array($raw)) {
            throw new \InvalidArgumentException(\sprintf('Invalid value for prompts: expected list of strings, got %s.', get_debug_type($raw)));
        }

        $paths = [];
        foreach ($raw as $index => $value) {
            if (!\is_string($value)) {
                throw new \InvalidArgumentException(\sprintf('Invalid value for prompts[%d]: expected a non-empty string, got %s.', $index, get_debug_type($value)));
            }
            if ('' === trim($value)) {
                throw new \InvalidArgumentException(\sprintf('Invalid value for prompts[%d]: expected a non-empty string, got blank string.', $index));
            }
            $paths[] = $value;
        }

        return new self($paths);
    }

    public static function fromAppConfig(AppConfig $appConfig): self
    {
        return $appConfig->prompts;
    }
}
