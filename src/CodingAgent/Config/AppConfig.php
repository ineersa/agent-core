<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Resolved Hatfield application configuration.
 *
 * Immutable DTO produced by {@see AppConfigResolver}.
 * Contains all settings loaded from defaults, home, and project YAML.
 *
 * Sections (more will be added as the app grows):
 *  - tui       Theme, theme paths, terminal settings
 *  - sessions  Session storage location
 */
final readonly class AppConfig
{
    public function __construct(
        public TuiConfig $tui,
        /** @var array<string, mixed> */
        public array $sessions = [],
        /** @var array<string, mixed> Raw merged data for forward compatibility */
        public array $raw = [],
    ) {
    }

    /**
     * Create from a fully merged array (already through precedence resolution).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            tui: TuiConfig::fromArray((array) ($data['tui'] ?? [])),
            sessions: (array) ($data['sessions'] ?? []),
            raw: $data,
        );
    }
}
