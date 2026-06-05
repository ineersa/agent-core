<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Resolved Hatfield application configuration.
 *
 * Contains all settings loaded from defaults, home, and project YAML.
 * All properties are populated by the production DI factory
 * {@see fromContainer} which calls {@see AppConfigLoader} and then
 * hydrates known sections through Symfony Serializer denormalization.
 *
 * Sections:
 *  - tui        TuiConfig (theme, theme_paths)
 *  - logging    LoggingConfig (path, level, max_files)
 *  - sessions   SessionsConfig (path)
 *  - extensions ExtensionsConfig (enabled list)
 *  - tools      ToolsConfig (execution, output_cap, background_process, image, …)
 *  - ai         AiConfig (provider and model configuration)
 *
 * The raw array is kept for forward compatibility with config keys
 * that do not yet have a typed DTO. Production consumers must use
 * typed DTOs for known sections; raw must not be used for sections
 * that have a corresponding DTO type.
 */
final class AppConfig
{
    public function __construct(
        public TuiConfig $tui,
        public LoggingConfig $logging,
        public SessionsConfig $sessions = new SessionsConfig(),
        public ExtensionsConfig $extensions = new ExtensionsConfig(),
        public ToolsConfig $tools = new ToolsConfig(),
        public ?AiConfig $ai = null,
        /** @var array<string, mixed> Raw merged data for forward compatibility */
        public array $raw = [],
        public ?HatfieldModelCatalog $catalog = null,
        /**
         * Canonical runtime working directory injected from %app.cwd%,
         * never from ambient getcwd(). Set during DI factory construction.
         */
        public string $cwd = '',
    ) {
    }

    /**
     * Production DI factory — loads and hydrates from Hatfield config layers
     * using Symfony Serializer denormalization for known sections.
     *
     * The canonical runtime cwd is injected from the container parameter
     * %app.cwd% (which is resolved from HATFIELD_CWD or kernel.project_dir),
     * not from ambient getcwd(). This ensures the cwd is consistent across
     * all services regardless of when they are constructed.
     *
     * Used by the Symfony container via services.yaml factory definition.
     */
    public static function fromContainer(
        AppConfigLoader $loader,
        AppResourceLocator $resources,
        DenormalizerInterface $denormalizer,
        string $cwd,
    ): self {
        $data = $loader->load($resources->getDefaultsPath(), $cwd);
        $ai = AiConfig::optionalFromArray($data);

        return new self(
            tui: $denormalizer->denormalize(
                (array) ($data['tui'] ?? []),
                TuiConfig::class,
            ),
            logging: $denormalizer->denormalize(
                (array) ($data['logging'] ?? []),
                LoggingConfig::class,
            ),
            sessions: $denormalizer->denormalize(
                (array) ($data['sessions'] ?? []),
                SessionsConfig::class,
            ),
            extensions: $denormalizer->denormalize(
                (array) ($data['extensions'] ?? []),
                ExtensionsConfig::class,
            ),
            tools: $denormalizer->denormalize(
                (array) ($data['tools'] ?? []),
                ToolsConfig::class,
            ),
            ai: $ai,
            raw: $data,
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: $cwd,
        );
    }
}
