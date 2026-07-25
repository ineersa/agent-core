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

    public const DEFAULT_REFLECTOR_SCHEMA_VERSION = 'om-reflector-v1';

    public const DEFAULT_MAX_OBSERVATIONS = 12;

    public const DEFAULT_OBSERVER_INPUT_BUDGET_TOKENS = 12_000;

    public const DEFAULT_TOOL_RESULT_MAX_CHARS = 4_000;

    public const DEFAULT_CONTENT_MAX_CHARS = 2_000;

    public const DEFAULT_WAIT_TIMEOUT_SECONDS = 180;

    public const DEFAULT_OBSERVATIONS_MAX_TOKENS = 30_000;

    public const DEFAULT_REFLECTIONS_MAX_TOKENS = 10_000;

    public const DEFAULT_REFLECTOR_INPUT_BUDGET_TOKENS = 20_000;

    public const DEFAULT_MAX_REFLECTIONS = 8;

    public const DEFAULT_REFLECTION_CONTENT_MAX_CHARS = 4_000;

    public const DEFAULT_REPLACEMENT_MAX_CHARS = 12_000;

    public function __construct(
        public bool $enabled,
        public string $databasePath,
        public ?string $observerModel,
        public ?string $reflectorModel,
        public string $rendererVersion,
        public string $observerSchemaVersion,
        public string $reflectorSchemaVersion,
        public int $maxObservations,
        public int $observerInputBudgetTokens,
        public int $toolResultMaxChars,
        public int $contentMaxChars,
        public int $waitTimeoutSeconds,
        public int $observationsMaxTokens,
        public int $reflectionsMaxTokens,
        public int $reflectorInputBudgetTokens,
        public int $maxReflections,
        public int $reflectionContentMaxChars,
        public int $replacementMaxChars,
    ) {
    }

    public static function fromApi(ExtensionApiInterface $api): self
    {
        return self::fromArray($api->getSettings(self::SETTINGS_KEY));
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $enabled = true;
        if (\array_key_exists('enabled', $raw)) {
            $enabled = filter_var($raw['enabled'], \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;
        }

        $databasePath = self::DEFAULT_RELATIVE_DB_PATH;
        if (isset($raw['database_path']) && \is_string($raw['database_path']) && '' !== $raw['database_path']) {
            $databasePath = $raw['database_path'];
        }

        $observerModel = self::readModel($raw, 'observer_model', 'observer');
        $reflectorModel = self::readModel($raw, 'reflector_model', 'reflector');

        $rendererVersion = self::DEFAULT_RENDERER_VERSION;
        if (isset($raw['renderer_version']) && \is_string($raw['renderer_version']) && '' !== $raw['renderer_version']) {
            $rendererVersion = $raw['renderer_version'];
        }

        $observerSchemaVersion = self::DEFAULT_OBSERVER_SCHEMA_VERSION;
        if (isset($raw['observer_schema_version']) && \is_string($raw['observer_schema_version']) && '' !== $raw['observer_schema_version']) {
            $observerSchemaVersion = $raw['observer_schema_version'];
        }

        $reflectorSchemaVersion = self::DEFAULT_REFLECTOR_SCHEMA_VERSION;
        if (isset($raw['reflector_schema_version']) && \is_string($raw['reflector_schema_version']) && '' !== $raw['reflector_schema_version']) {
            $reflectorSchemaVersion = $raw['reflector_schema_version'];
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

        $waitTimeout = self::DEFAULT_WAIT_TIMEOUT_SECONDS;
        if (isset($raw['compaction']) && \is_array($raw['compaction'])
            && isset($raw['compaction']['wait_timeout_seconds']) && is_numeric($raw['compaction']['wait_timeout_seconds'])) {
            $waitTimeout = max(1, (int) $raw['compaction']['wait_timeout_seconds']);
        } elseif (isset($raw['wait_timeout_seconds']) && is_numeric($raw['wait_timeout_seconds'])) {
            $waitTimeout = max(1, (int) $raw['wait_timeout_seconds']);
        }

        $observationsMaxTokens = self::DEFAULT_OBSERVATIONS_MAX_TOKENS;
        if (isset($raw['observations_max_tokens']) && is_numeric($raw['observations_max_tokens'])) {
            $observationsMaxTokens = max(256, (int) $raw['observations_max_tokens']);
        } elseif (isset($raw['pools']) && \is_array($raw['pools'])
            && isset($raw['pools']['observations_max_tokens']) && is_numeric($raw['pools']['observations_max_tokens'])) {
            $observationsMaxTokens = max(256, (int) $raw['pools']['observations_max_tokens']);
        }

        $reflectionsMaxTokens = self::DEFAULT_REFLECTIONS_MAX_TOKENS;
        if (isset($raw['reflections_max_tokens']) && is_numeric($raw['reflections_max_tokens'])) {
            $reflectionsMaxTokens = max(256, (int) $raw['reflections_max_tokens']);
        } elseif (isset($raw['pools']) && \is_array($raw['pools'])
            && isset($raw['pools']['reflections_max_tokens']) && is_numeric($raw['pools']['reflections_max_tokens'])) {
            $reflectionsMaxTokens = max(256, (int) $raw['pools']['reflections_max_tokens']);
        }

        $reflectorInputBudget = self::DEFAULT_REFLECTOR_INPUT_BUDGET_TOKENS;
        if (isset($raw['reflector_input_budget_tokens']) && is_numeric($raw['reflector_input_budget_tokens'])) {
            $reflectorInputBudget = max(256, (int) $raw['reflector_input_budget_tokens']);
        }

        $maxReflections = self::DEFAULT_MAX_REFLECTIONS;
        if (isset($raw['max_reflections']) && is_numeric($raw['max_reflections'])) {
            $maxReflections = max(1, (int) $raw['max_reflections']);
        }

        $reflectionContentMaxChars = self::DEFAULT_REFLECTION_CONTENT_MAX_CHARS;
        if (isset($raw['reflection_content_max_chars']) && is_numeric($raw['reflection_content_max_chars'])) {
            $reflectionContentMaxChars = max(64, (int) $raw['reflection_content_max_chars']);
        }

        $replacementMaxChars = self::DEFAULT_REPLACEMENT_MAX_CHARS;
        if (isset($raw['replacement_max_chars']) && is_numeric($raw['replacement_max_chars'])) {
            $replacementMaxChars = max(256, (int) $raw['replacement_max_chars']);
        }

        return new self(
            enabled: $enabled,
            databasePath: $databasePath,
            observerModel: $observerModel,
            reflectorModel: $reflectorModel,
            rendererVersion: $rendererVersion,
            observerSchemaVersion: $observerSchemaVersion,
            reflectorSchemaVersion: $reflectorSchemaVersion,
            maxObservations: $maxObservations,
            observerInputBudgetTokens: $budget,
            toolResultMaxChars: $toolResultMaxChars,
            contentMaxChars: $contentMaxChars,
            waitTimeoutSeconds: $waitTimeout,
            observationsMaxTokens: $observationsMaxTokens,
            reflectionsMaxTokens: $reflectionsMaxTokens,
            reflectorInputBudgetTokens: $reflectorInputBudget,
            maxReflections: $maxReflections,
            reflectionContentMaxChars: $reflectionContentMaxChars,
            replacementMaxChars: $replacementMaxChars,
        );
    }

    public function requireObserverModel(): string
    {
        return $this->requireExactModel($this->observerModel, 'observational_memory.observer_model');
    }

    public function requireReflectorModel(): string
    {
        return $this->requireExactModel($this->reflectorModel, 'observational_memory.reflector_model');
    }

    /**
     * Deterministic request fingerprint inputs for compaction jobs.
     *
     * @return array<string, scalar>
     */
    public function compactionIdentityParts(): array
    {
        return [
            'renderer_version' => $this->rendererVersion,
            'observer_schema_version' => $this->observerSchemaVersion,
            'reflector_schema_version' => $this->reflectorSchemaVersion,
            'reflector_model' => $this->reflectorModel ?? '',
            'observer_model' => $this->observerModel ?? '',
            'max_observations' => $this->maxObservations,
            'observer_input_budget_tokens' => $this->observerInputBudgetTokens,
            'tool_result_max_chars' => $this->toolResultMaxChars,
            'content_max_chars' => $this->contentMaxChars,
            'observations_max_tokens' => $this->observationsMaxTokens,
            'reflections_max_tokens' => $this->reflectionsMaxTokens,
            'reflector_input_budget_tokens' => $this->reflectorInputBudgetTokens,
            'max_reflections' => $this->maxReflections,
            'reflection_content_max_chars' => $this->reflectionContentMaxChars,
            'replacement_max_chars' => $this->replacementMaxChars,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function readModel(array $raw, string $flatKey, string $nestedKey): ?string
    {
        if (isset($raw[$flatKey]) && \is_string($raw[$flatKey]) && '' !== trim($raw[$flatKey])) {
            return trim($raw[$flatKey]);
        }
        if (isset($raw[$nestedKey]) && \is_array($raw[$nestedKey])
            && isset($raw[$nestedKey]['model']) && \is_string($raw[$nestedKey]['model']) && '' !== trim($raw[$nestedKey]['model'])) {
            return trim($raw[$nestedKey]['model']);
        }

        return null;
    }

    private function requireExactModel(?string $model, string $label): string
    {
        if (null === $model || '' === $model) {
            throw new \RuntimeException($label.' (exact provider/model) is required.');
        }

        if (str_starts_with($model, '@') || !str_contains($model, '/')) {
            throw new \RuntimeException($label.' must be an exact provider/model reference (provider/model).');
        }

        return $model;
    }
}
