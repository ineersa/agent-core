<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

/**
 * Write-only persistence for model selection and reasoning changes.
 *
 * Persists defaults to home settings YAML and current state to session
 * metadata.  Trusts its input — validation is the caller's responsibility.
 */
final class ModelSettingsPersister
{
    public function __construct(
        private readonly HomeSettingsWriter $homeWriter,
        private readonly SessionMetadataStore $sessionMetaStore,
    ) {
    }

    /**
     * Persist the model to home settings and session metadata.
     *
     * @param string $modelString The full provider/modelname string
     * @param string $sessionId   Session ID for metadata lookup
     */
    public function persistModel(string $modelString, string $providerId, string $modelName, string $sessionId): void
    {
        $this->homeWriter->writeDefaultModel($modelString);
        $this->sessionMetaStore->writeSessionMetadata($sessionId, [
            'model' => $modelString,
            'model_provider' => $providerId,
            'model_name' => $modelName,
        ]);
    }

    /**
     * Persist the reasoning level to home settings and session metadata.
     *
     * @throws \InvalidArgumentException If the level is not a valid reasoning level
     */
    public function persistReasoning(string $level, string $sessionId): void
    {
        if (!\in_array($level, ModelResolver::LEVELS, true)) {
            throw new \InvalidArgumentException(\sprintf('Invalid reasoning level "%s". Valid levels: %s.', $level, implode(', ', ModelResolver::LEVELS)));
        }

        $this->homeWriter->writeDefaultReasoning($level);
        $this->sessionMetaStore->writeSessionMetadata($sessionId, [
            'reasoning' => $level,
        ]);
    }

    /**
     * Persist the full favorite models list to home settings.
     *
     * @param list<string> $models List of "provider/modelname" strings
     */
    public function persistFavoriteModels(array $models): void
    {
        $this->homeWriter->writeFavoriteModels($models);
    }
}
