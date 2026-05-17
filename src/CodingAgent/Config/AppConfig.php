<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;

/**
 * Resolved Hatfield application configuration.
 *
 * Immutable DTO produced by {@see AppConfigResolver}.
 * Contains all settings loaded from defaults, home, and project YAML.
 *
 * Sections (more will be added as the app grows):
 *  - tui       Theme, theme paths, terminal settings
 *  - sessions  Session storage location
 *  - ai        AI provider and model configuration
 */
final readonly class AppConfig
{
    public function __construct(
        public TuiConfig $tui,
        /** @var array<string, mixed> */
        public array $sessions = [],
        public ?AiConfig $ai = null,
        /** @var array<string, mixed> Raw merged data for forward compatibility */
        public array $raw = [],
        public ?HatfieldModelCatalog $catalog = null,
    ) {
    }

    /**
     * Create from a fully merged array (already through precedence resolution).
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $aiConfig = AiConfig::optionalFromArray($data);
        $catalog = null !== $aiConfig ? new HatfieldModelCatalog($aiConfig) : null;

        return new self(
            tui: TuiConfig::fromArray((array) ($data['tui'] ?? [])),
            sessions: (array) ($data['sessions'] ?? []),
            ai: $aiConfig,
            raw: $data,
            catalog: $catalog,
        );
    }
}
