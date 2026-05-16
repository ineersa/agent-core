<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

/**
 * Authoritative model catalog built from Hatfield AI settings.
 *
 * Provides lookup methods for providers, models, and availability checks.
 * All behavior is explicit-only: unknown model names are rejected for
 * every provider, including llama.cpp.
 */
final readonly class HatfieldModelCatalog
{
    /**
     * @param AiConfig $config The parsed AI configuration
     */
    public function __construct(
        private AiConfig $config,
    ) {
    }

    /**
     * Returns the underlying AI config (for advanced consumers).
     */
    public function config(): AiConfig
    {
        return $this->config;
    }

    /**
     * Get a provider by ID.
     */
    public function getProvider(string $id): ?AiProviderConfig
    {
        return $this->config->providers[$id] ?? null;
    }

    /**
     * Get a model definition by reference or string.
     *
     * @param AiModelRef|string $ref Model reference or "provider/model" string
     */
    public function getModel(AiModelRef|string $ref): ?AiModelDefinition
    {
        if (\is_string($ref)) {
            $ref = AiModelRef::tryParse($ref);
            if (null === $ref) {
                return null;
            }
        }

        $provider = $this->getProvider($ref->providerId);
        if (null === $provider || !$provider->enabled) {
            return null;
        }

        return $provider->models[$ref->modelName] ?? null;
    }

    /**
     * Get a model definition, throwing if not found.
     *
     * @param AiModelRef|string $ref
     *
     * @throws \RuntimeException if the model is not in the catalog
     */
    public function requireModel(AiModelRef|string $ref): AiModelDefinition
    {
        $model = $this->getModel($ref);

        if (null === $model) {
            $refStr = \is_string($ref) ? $ref : $ref->toString();
            throw new \RuntimeException(
                \sprintf('Model "%s" is not available in the configured AI providers.', $refStr),
            );
        }

        return $model;
    }

    /**
     * Check whether a model is available (configured, enabled, and listed).
     *
     * @param AiModelRef|string $ref
     */
    public function isAvailable(AiModelRef|string $ref): bool
    {
        return null !== $this->getModel($ref);
    }

    /**
     * Return all available model references across all enabled providers.
     *
     * @return list<AiModelRef>
     */
    public function allModels(): array
    {
        $refs = [];

        foreach ($this->config->providers as $provider) {
            if (!$provider->enabled) {
                continue;
            }

            foreach ($provider->models as $modelName => $model) {
                $refs[] = new AiModelRef($provider->id, $modelName);
            }
        }

        return $refs;
    }

    /**
     * Get the default model reference from config, or null.
     */
    public function defaultModelRef(): ?AiModelRef
    {
        if (null === $this->config->defaultModel || '' === $this->config->defaultModel) {
            return null;
        }

        return AiModelRef::tryParse($this->config->defaultModel);
    }

    /**
     * Get the first available model across all providers.
     */
    public function firstAvailableModel(): ?AiModelRef
    {
        $all = $this->allModels();

        return $all[0] ?? null;
    }
}
