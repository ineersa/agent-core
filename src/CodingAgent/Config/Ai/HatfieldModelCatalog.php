<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

use Ineersa\Hatfield\ExtensionApi\Model\AiModelReference;

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
     * @param AiModelReference|string $ref Model reference or "provider/model" string
     */
    public function getModel(AiModelReference|string $ref): ?AiModelDefinition
    {
        if (\is_string($ref)) {
            $ref = AiModelReference::tryParse($ref);
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
     * @throws \RuntimeException if the model is not in the catalog
     */
    public function requireModel(AiModelReference|string $ref): AiModelDefinition
    {
        $model = $this->getModel($ref);

        if (null === $model) {
            $refStr = \is_string($ref) ? $ref : $ref->toString();
            throw new \RuntimeException(\sprintf('Model "%s" is not available in the configured AI providers.', $refStr));
        }

        return $model;
    }

    /**
     * Check whether a model is available (configured, enabled, and listed).
     */
    public function isAvailable(AiModelReference|string $ref): bool
    {
        return null !== $this->getModel($ref);
    }

    /**
     * Return all available model references across all enabled providers.
     *
     * @return list<AiModelReference>
     */
    public function allModels(): array
    {
        $refs = [];

        foreach ($this->config->providers as $provider) {
            if (!$provider->enabled) {
                continue;
            }

            foreach ($provider->models as $modelName => $model) {
                $refs[] = new AiModelReference($provider->id, $modelName);
            }
        }

        return $refs;
    }

    /**
     * Get the default model reference from config, or null.
     */
    public function defaultModelReference(): ?AiModelReference
    {
        if (null === $this->config->defaultModel || '' === $this->config->defaultModel) {
            return null;
        }

        return AiModelReference::tryParse($this->config->defaultModel);
    }

    /**
     * Get the first available model across all providers.
     */
    public function firstAvailableModel(): ?AiModelReference
    {
        $all = $this->allModels();

        return $all[0] ?? null;
    }

    /**
     * Check whether reasoning-level cycling is meaningful for a model.
     *
     * Considers both the provider-level supports_thinking_levels flag and
     * the per-model reasoning flag. A model whose provider does not support
     * thinking levels yields false even when reasoning is true.
     */
    public function supportsThinkingLevels(AiModelReference|string $ref): bool
    {
        if (\is_string($ref)) {
            $ref = AiModelReference::tryParse($ref);
            if (null === $ref) {
                return false;
            }
        }

        $provider = $this->getProvider($ref->providerId);
        if (null === $provider) {
            return false;
        }

        if (!$provider->supportsThinkingLevels) {
            return false;
        }

        $model = $provider->models[$ref->modelName] ?? null;

        return null !== $model && $model->reasoning;
    }
}
