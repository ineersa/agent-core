<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;

/**
 * Nested observational_memory settings (task §M).
 *
 * Flat budget keys are not supported; replace, do not dual-read.
 */
final readonly class OmSettings
{
    public const string SETTINGS_KEY = 'observational_memory';

    public const string DEFAULT_RELATIVE_DB_PATH = '.hatfield/extensions-data/observational-memory/om.sqlite';

    public const string DEFAULT_RENDERER_VERSION = '1';

    public const string DEFAULT_OBSERVER_SCHEMA_VERSION = '1';

    public const string DEFAULT_REFLECTOR_SCHEMA_VERSION = '1';

    public const float DEFAULT_CONTEXT_WINDOW_RATIO = 0.65;

    public const int DEFAULT_REFLECT_AFTER_OBSERVATION_TOKENS = 40_000;

    public const int DEFAULT_OBSERVATIONS_MAX_TOKENS = 30_000;

    public const int DEFAULT_REFLECTIONS_MAX_TOKENS = 10_000;

    public const string DEFAULT_OBSERVER_THINKING_LEVEL = 'medium';

    public const string DEFAULT_REFLECTOR_THINKING_LEVEL = 'high';

    public function __construct(
        public string $databasePath,
        public ?string $observerModel,
        public string $observerThinkingLevel,
        public float $observerContextWindowRatio,
        public string $rendererVersion,
        public string $observerSchemaVersion,
        public ?string $reflectorModel,
        public string $reflectorThinkingLevel,
        public float $reflectorContextWindowRatio,
        public int $reflectAfterObservationTokens,
        public string $reflectorSchemaVersion,
        public int $observationsMaxTokens,
        public int $reflectionsMaxTokens,
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
        $databasePath = self::DEFAULT_RELATIVE_DB_PATH;
        $storage = $raw['storage'] ?? null;
        if (\is_array($storage) && isset($storage['database']) && \is_string($storage['database']) && '' !== $storage['database']) {
            $databasePath = $storage['database'];
        }

        $observer = \is_array($raw['observer'] ?? null) ? $raw['observer'] : [];
        $reflector = \is_array($raw['reflector'] ?? null) ? $raw['reflector'] : [];
        $pools = \is_array($raw['pools'] ?? null) ? $raw['pools'] : [];

        $observerModel = self::readNestedModel($observer);
        $reflectorModel = self::readNestedModel($reflector);

        $observerThinking = self::DEFAULT_OBSERVER_THINKING_LEVEL;
        if (isset($observer['thinking_level']) && \is_string($observer['thinking_level']) && '' !== trim($observer['thinking_level'])) {
            $observerThinking = trim($observer['thinking_level']);
        }

        $reflectorThinking = self::DEFAULT_REFLECTOR_THINKING_LEVEL;
        if (isset($reflector['thinking_level']) && \is_string($reflector['thinking_level']) && '' !== trim($reflector['thinking_level'])) {
            $reflectorThinking = trim($reflector['thinking_level']);
        }

        $observerRatio = self::readRatio($observer['context_window_ratio'] ?? null, self::DEFAULT_CONTEXT_WINDOW_RATIO);
        $reflectorRatio = self::readRatio($reflector['context_window_ratio'] ?? null, self::DEFAULT_CONTEXT_WINDOW_RATIO);

        $rendererVersion = self::DEFAULT_RENDERER_VERSION;
        if (isset($observer['renderer_version']) && \is_string($observer['renderer_version']) && '' !== $observer['renderer_version']) {
            $rendererVersion = $observer['renderer_version'];
        }

        $observerSchemaVersion = self::DEFAULT_OBSERVER_SCHEMA_VERSION;
        if (isset($observer['schema_version']) && \is_string($observer['schema_version']) && '' !== $observer['schema_version']) {
            $observerSchemaVersion = $observer['schema_version'];
        }

        $reflectorSchemaVersion = self::DEFAULT_REFLECTOR_SCHEMA_VERSION;
        if (isset($reflector['schema_version']) && \is_string($reflector['schema_version']) && '' !== $reflector['schema_version']) {
            $reflectorSchemaVersion = $reflector['schema_version'];
        }

        $reflectAfter = self::DEFAULT_REFLECT_AFTER_OBSERVATION_TOKENS;
        if (isset($reflector['reflect_after_observation_tokens']) && is_numeric($reflector['reflect_after_observation_tokens'])) {
            $reflectAfter = max(1, (int) $reflector['reflect_after_observation_tokens']);
        }

        $observationsMaxTokens = self::DEFAULT_OBSERVATIONS_MAX_TOKENS;
        if (isset($pools['observations_max_tokens']) && is_numeric($pools['observations_max_tokens'])) {
            $observationsMaxTokens = max(1, (int) $pools['observations_max_tokens']);
        }

        $reflectionsMaxTokens = self::DEFAULT_REFLECTIONS_MAX_TOKENS;
        if (isset($pools['reflections_max_tokens']) && is_numeric($pools['reflections_max_tokens'])) {
            $reflectionsMaxTokens = max(1, (int) $pools['reflections_max_tokens']);
        }

        return new self(
            databasePath: $databasePath,
            observerModel: $observerModel,
            observerThinkingLevel: $observerThinking,
            observerContextWindowRatio: $observerRatio,
            rendererVersion: $rendererVersion,
            observerSchemaVersion: $observerSchemaVersion,
            reflectorModel: $reflectorModel,
            reflectorThinkingLevel: $reflectorThinking,
            reflectorContextWindowRatio: $reflectorRatio,
            reflectAfterObservationTokens: $reflectAfter,
            reflectorSchemaVersion: $reflectorSchemaVersion,
            observationsMaxTokens: $observationsMaxTokens,
            reflectionsMaxTokens: $reflectionsMaxTokens,
        );
    }

    public function requireObserverModel(): string
    {
        return $this->requireExactModel($this->observerModel, 'observational_memory.observer.model');
    }

    public function requireReflectorModel(): string
    {
        return $this->requireExactModel($this->reflectorModel, 'observational_memory.reflector.model');
    }

    /**
     * Immutable copy with job-payload renderer/observer schema versions only.
     */
    public function withRendererAndObserverVersions(string $rendererVersion, string $observerSchemaVersion): self
    {
        return new self(
            databasePath: $this->databasePath,
            observerModel: $this->observerModel,
            observerThinkingLevel: $this->observerThinkingLevel,
            observerContextWindowRatio: $this->observerContextWindowRatio,
            rendererVersion: $rendererVersion,
            observerSchemaVersion: $observerSchemaVersion,
            reflectorModel: $this->reflectorModel,
            reflectorThinkingLevel: $this->reflectorThinkingLevel,
            reflectorContextWindowRatio: $this->reflectorContextWindowRatio,
            reflectAfterObservationTokens: $this->reflectAfterObservationTokens,
            reflectorSchemaVersion: $this->reflectorSchemaVersion,
            observationsMaxTokens: $this->observationsMaxTokens,
            reflectionsMaxTokens: $this->reflectionsMaxTokens,
        );
    }

    public function observerEnvelope(int $contextWindow): int
    {
        return self::envelope($contextWindow, $this->observerContextWindowRatio);
    }

    public static function envelope(int $contextWindow, float $ratio): int
    {
        if ($contextWindow <= 0) {
            throw new \InvalidArgumentException('context_window must be positive.');
        }

        return (int) floor($contextWindow * $ratio);
    }

    /**
     * @param array<string, mixed> $section
     */
    private static function readNestedModel(array $section): ?string
    {
        if (isset($section['model']) && \is_string($section['model']) && '' !== trim($section['model'])) {
            return trim($section['model']);
        }

        return null;
    }

    private static function readRatio(mixed $raw, float $default): float
    {
        if (!is_numeric($raw)) {
            return $default;
        }

        $ratio = (float) $raw;
        if ($ratio <= 0.0 || $ratio >= 1.0) {
            throw new \InvalidArgumentException('context_window_ratio must satisfy 0 < ratio < 1.');
        }

        return $ratio;
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
