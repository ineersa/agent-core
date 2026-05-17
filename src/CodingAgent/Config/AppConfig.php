<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;

/**
 * Resolved Hatfield application configuration.
 *
 * Contains all settings loaded from defaults, home, and project YAML.
 * All properties are populated by the production DI factory
 * {@see fromContainer} which calls {@see AppConfigLoader}.
 *
 * Sections (more will be added as the app grows):
 *  - tui       Theme, theme paths, terminal settings
 *  - sessions  Session storage location
 *  - ai        AI provider and model configuration
 *
 * The value constructor accepts all properties directly for testing
 * and internal construction. Production code uses {@see fromContainer}.
 */
final class AppConfig
{
    public function __construct(
        public TuiConfig $tui,
        /** @var array<string, mixed> */
        public array $sessions = [],
        public ?AiConfig $ai = null,
        /** @var array<string, mixed> Raw merged data for forward compatibility */
        public array $raw = [],
        public ?HatfieldModelCatalog $catalog = null,
        public string $cwd = '',
    ) {
    }

    /**
     * Production DI factory — loads and hydrates from Hatfield config layers.
     *
     * Used by the Symfony container via services.yaml factory definition.
     *
     * @throws \RuntimeException when the current working directory is unavailable
     */
    public static function fromContainer(AppConfigLoader $loader, AppResourceLocator $resources): self
    {
        $cwd = self::resolveCurrentWorkingDirectory();
        $data = $loader->load($resources->getDefaultsPath());
        $ai = AiConfig::optionalFromArray($data);

        return new self(
            tui: TuiConfig::fromArray((array) ($data['tui'] ?? [])),
            sessions: (array) ($data['sessions'] ?? []),
            ai: $ai,
            raw: $data,
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: $cwd,
        );
    }

    /**
     * Throws early when the process has no working directory rather than
     * silently falling back to "/" and producing broken paths downstream.
     *
     * @throws \RuntimeException
     */
    private static function resolveCurrentWorkingDirectory(): string
    {
        $cwd = getcwd();

        if (false === $cwd) {
            throw new \RuntimeException('No current working directory available.');
        }

        return $cwd;
    }
}
