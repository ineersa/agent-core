<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

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
 *  - prompts    PromptsConfig (custom system/user prompt overrides)
 *  - compaction CompactionConfig (auto_enabled, compact_after_tokens, keep_recent_tokens, model, thinking_level, provider_overrides, model_overrides)
 *  - context_budget_reminders ContextBudgetReminderConfig (early_input_tokens, urgent_remaining_tokens)
 *  - agents     AgentsConfig (enabled, paths)
 *  - forks      ForksConfigDTO (model, thinking_level)
 *  - runtime    RuntimeConfig (llm_worker_count)
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
        public PromptsConfig $prompts = new PromptsConfig(),
        public CompactionConfig $compaction = new CompactionConfig(),
        public ContextBudgetReminderConfig $contextBudgetReminders = new ContextBudgetReminderConfig(),
        public ForksConfigDTO $forks = new ForksConfigDTO(),
        public AgentsConfig $agents = new AgentsConfig(),
        public RuntimeConfig $runtime = new RuntimeConfig(),
        /** @var array<string, mixed> Raw merged data for forward compatibility */
        public array $raw = [],
        public ?HatfieldModelCatalog $catalog = null,
        /**
         * Canonical runtime working directory injected from %app.cwd%,
         * never from ambient getcwd(). Set during DI factory construction.
         */
        public string $cwd = '',
        /**
         * Configured ai.default_model that was unavailable at boot.
         * When set, {@see $ai} already carries the first-available fallback.
         * Null when the configured default was missing, empty, or available.
         */
        public ?string $staleDefaultModel = null,
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
        ValidatorInterface $validator,
    ): self {
        $data = $loader->load($resources->getDefaultsPath(), $cwd)->effective;
        [$ai, $catalog, $staleDefaultModel] = self::resolveAiDefaultModel($data);

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
            prompts: \array_key_exists('prompts', $data)
                ? PromptsConfig::fromRaw($data['prompts'])
                : new PromptsConfig(),
            compaction: $denormalizer->denormalize(
                (array) ($data['compaction'] ?? []),
                CompactionConfig::class,
            ),
            contextBudgetReminders: $denormalizer->denormalize(
                (array) ($data['context_budget_reminders'] ?? []),
                ContextBudgetReminderConfig::class,
            ),
            forks: \array_key_exists('forks', $data)
                ? ForksConfigDTO::fromRaw($data['forks'])
                : new ForksConfigDTO(),
            agents: \array_key_exists('agents', $data)
                ? AgentsConfig::fromRaw($data['agents'])
                : new AgentsConfig(),
            runtime: self::denormalizeAndValidateRuntimeConfig($data, $denormalizer, $validator),
            raw: $data,
            catalog: $catalog,
            cwd: $cwd,
            staleDefaultModel: $staleDefaultModel,
        );
    }

    /**
     * Denormalize runtime config and enforce Symfony Validator attributes
     * (e.g. runtime.llm_worker_count Range 1..8) via the container validator.
     *
     * @param array<string, mixed> $data
     */
    private static function denormalizeAndValidateRuntimeConfig(
        array $data,
        DenormalizerInterface $denormalizer,
        ValidatorInterface $validator,
    ): RuntimeConfig {
        $runtime = $denormalizer->denormalize(
            (array) ($data['runtime'] ?? []),
            RuntimeConfig::class,
        );

        $violations = $validator->validate($runtime);

        if (\count($violations) > 0) {
            throw new ValidationFailedException($runtime, $violations);
        }

        return $runtime;
    }

    /**
     * Parse ai config, reject malformed default_model format, and fall back
     * to the first available model when the configured default is unavailable.
     *
     * Does not mutate $data — AppConfig::$raw must stay faithful to disk so
     * SettingsShowCommandHandler's restart-required check stays accurate.
     * Builds the effective AiConfig/catalog from a copy. Returns the stale
     * configured ref for the TUI startup warning; null when no fallback ran.
     *
     * Zero-provider boots no longer throw here — AgentCommand's enabled-provider
     * gate owns that failure with the providers:setup hint.
     *
     * @param array<string, mixed> $data
     *
     * @return array{0: ?AiConfig, 1: ?HatfieldModelCatalog, 2: ?string}
     */
    private static function resolveAiDefaultModel(array $data): array
    {
        $ai = AiConfig::optionalFromArray($data);
        if (null === $ai) {
            return [null, null, null];
        }

        $catalog = new HatfieldModelCatalog($ai);
        $defaultModel = $ai->defaultModel;
        if (null === $defaultModel || '' === $defaultModel) {
            return [$ai, $catalog, null];
        }

        $ref = AiModelReference::tryParse($defaultModel);
        if (null === $ref) {
            throw new \RuntimeException(\sprintf('Configured ai.default_model "%s" is invalid. Expected format: provider/model.', $defaultModel));
        }

        if ($catalog->isAvailable($ref)) {
            return [$ai, $catalog, null];
        }

        $fallback = $catalog->firstAvailableModel();
        if (null === $fallback) {
            // Keep the configured (unavailable) default; AgentCommand gate fires.
            return [$ai, $catalog, $defaultModel];
        }

        // optionalFromArray already proved $data['ai'] is an array.
        $ai = AiConfig::fromArray(['default_model' => $fallback->toString()] + $data['ai']);
        $catalog = new HatfieldModelCatalog($ai);

        return [$ai, $catalog, $defaultModel];
    }
}
