<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;

/**
 * Nested observational_memory settings.
 *
 * One shared top-level model for Observer, Reflector, and Dropper.
 * No thinking levels; provider defaults apply.
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

    /** Closest Hatfield mapping of Pi agentMaxTurns=16. */
    public const int DEFAULT_AGENT_MAX_TOOL_CALLS = 16;

    public function __construct(
        public string $databasePath,
        public ?string $model,
        public float $observerContextWindowRatio,
        public string $rendererVersion,
        public string $observerSchemaVersion,
        public int $reflectAfterObservationTokens,
        public string $reflectorSchemaVersion,
        public int $observationsMaxTokens,
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

        $model = null;
        if (isset($raw['model']) && \is_string($raw['model']) && '' !== trim($raw['model'])) {
            $model = trim($raw['model']);
        }

        $observer = \is_array($raw['observer'] ?? null) ? $raw['observer'] : [];
        $reflector = \is_array($raw['reflector'] ?? null) ? $raw['reflector'] : [];
        $pools = \is_array($raw['pools'] ?? null) ? $raw['pools'] : [];

        $observerRatio = self::readRatio($observer['context_window_ratio'] ?? null, self::DEFAULT_CONTEXT_WINDOW_RATIO);

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

        return new self(
            databasePath: $databasePath,
            model: $model,
            observerContextWindowRatio: $observerRatio,
            rendererVersion: $rendererVersion,
            observerSchemaVersion: $observerSchemaVersion,
            reflectAfterObservationTokens: $reflectAfter,
            reflectorSchemaVersion: $reflectorSchemaVersion,
            observationsMaxTokens: $observationsMaxTokens,
        );
    }

    public function requireModel(): string
    {
        return $this->requireExactModel($this->model, 'observational_memory.model');
    }

    /**
     * Immutable copy with job-payload renderer/observer schema versions only.
     */
    public function withRendererAndObserverVersions(string $rendererVersion, string $observerSchemaVersion): self
    {
        return new self(
            databasePath: $this->databasePath,
            model: $this->model,
            observerContextWindowRatio: $this->observerContextWindowRatio,
            rendererVersion: $rendererVersion,
            observerSchemaVersion: $observerSchemaVersion,
            reflectAfterObservationTokens: $this->reflectAfterObservationTokens,
            reflectorSchemaVersion: $this->reflectorSchemaVersion,
            observationsMaxTokens: $this->observationsMaxTokens,
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
