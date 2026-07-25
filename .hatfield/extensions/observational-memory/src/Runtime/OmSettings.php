<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;

/**
 * Extension-local settings for observational memory.
 *
 * Read from extensions.settings.observational_memory via ExtensionApi.
 */
final readonly class OmSettings
{
    public const SETTINGS_KEY = 'observational_memory';

    public const DEFAULT_RELATIVE_DB_PATH = '.hatfield/extensions-data/observational-memory/om.sqlite';

    public const DEFAULT_RENDERER_VERSION = 'om-renderer-v1';

    public const DEFAULT_OBSERVER_SCHEMA_VERSION = 'om-observer-v1';

    public const DEFAULT_MAX_OBSERVATIONS = 12;

    public const DEFAULT_OBSERVER_INPUT_BUDGET_TOKENS = 12_000;

    public const DEFAULT_TOOL_RESULT_MAX_CHARS = 4_000;

    public const DEFAULT_CONTENT_MAX_CHARS = 2_000;

    public function __construct(
        public bool $enabled,
        public string $databasePath,
        public ?string $observerModel,
        public string $rendererVersion,
        public string $observerSchemaVersion,
        public int $maxObservations,
        public int $observerInputBudgetTokens,
        public int $toolResultMaxChars,
        public int $contentMaxChars,
    ) {
    }

    public static function fromApi(ExtensionApiInterface $api): self
    {
        $raw = $api->getSettings(self::SETTINGS_KEY);

        $enabled = true;
        if (\array_key_exists('enabled', $raw)) {
            $enabled = filter_var($raw['enabled'], \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;
        }

        $databasePath = self::DEFAULT_RELATIVE_DB_PATH;
        if (isset($raw['database_path']) && \is_string($raw['database_path']) && '' !== $raw['database_path']) {
            $databasePath = $raw['database_path'];
        }

        $observerModel = null;
        if (isset($raw['observer_model']) && \is_string($raw['observer_model']) && '' !== trim($raw['observer_model'])) {
            $observerModel = trim($raw['observer_model']);
        } elseif (isset($raw['observer']) && \is_array($raw['observer'])
            && isset($raw['observer']['model']) && \is_string($raw['observer']['model']) && '' !== trim($raw['observer']['model'])) {
            $observerModel = trim($raw['observer']['model']);
        }

        $rendererVersion = self::DEFAULT_RENDERER_VERSION;
        if (isset($raw['renderer_version']) && \is_string($raw['renderer_version']) && '' !== $raw['renderer_version']) {
            $rendererVersion = $raw['renderer_version'];
        }

        $observerSchemaVersion = self::DEFAULT_OBSERVER_SCHEMA_VERSION;
        if (isset($raw['observer_schema_version']) && \is_string($raw['observer_schema_version']) && '' !== $raw['observer_schema_version']) {
            $observerSchemaVersion = $raw['observer_schema_version'];
        }

        $maxObservations = self::DEFAULT_MAX_OBSERVATIONS;
        if (isset($raw['max_observations']) && is_numeric($raw['max_observations'])) {
            $maxObservations = max(1, (int) $raw['max_observations']);
        }

        $budget = self::DEFAULT_OBSERVER_INPUT_BUDGET_TOKENS;
        if (isset($raw['observer_input_budget_tokens']) && is_numeric($raw['observer_input_budget_tokens'])) {
            $budget = max(256, (int) $raw['observer_input_budget_tokens']);
        }

        $toolResultMaxChars = self::DEFAULT_TOOL_RESULT_MAX_CHARS;
        if (isset($raw['tool_result_max_chars']) && is_numeric($raw['tool_result_max_chars'])) {
            $toolResultMaxChars = max(256, (int) $raw['tool_result_max_chars']);
        }

        $contentMaxChars = self::DEFAULT_CONTENT_MAX_CHARS;
        if (isset($raw['content_max_chars']) && is_numeric($raw['content_max_chars'])) {
            $contentMaxChars = max(64, (int) $raw['content_max_chars']);
        }

        return new self(
            enabled: $enabled,
            databasePath: $databasePath,
            observerModel: $observerModel,
            rendererVersion: $rendererVersion,
            observerSchemaVersion: $observerSchemaVersion,
            maxObservations: $maxObservations,
            observerInputBudgetTokens: $budget,
            toolResultMaxChars: $toolResultMaxChars,
            contentMaxChars: $contentMaxChars,
        );
    }

    public function requireObserverModel(): string
    {
        if (null === $this->observerModel || '' === $this->observerModel) {
            throw new \RuntimeException('observational_memory.observer_model (exact provider/model) is required for Observer jobs.');
        }

        if (str_starts_with($this->observerModel, '@') || !str_contains($this->observerModel, '/')) {
            throw new \RuntimeException('observational_memory.observer_model must be an exact provider/model reference (provider/model).');
        }

        return $this->observerModel;
    }
}
