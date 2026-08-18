<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

/**
 * Builds the catalog settings layer: curated yaml + models.dev metadata refresh.
 *
 * Output shape is `{ ai: { providers: { ... } } }` ready for AppConfigLoader overlay
 * as the lowest layer beneath hatfield.defaults.yaml.
 */
final class AiCatalogMerge
{
    public function __construct(
        private readonly AiCatalogLoader $catalogLoader = new AiCatalogLoader(),
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function buildLayer(string $catalogPath, ?ModelsDevCache $cache = null): array
    {
        $providers = $this->catalogLoader->loadProviders($catalogPath);
        if ([] === $providers) {
            return [];
        }

        $modelsDev = null !== $cache ? $cache->loadFilteredProviders() : [];
        $refreshed = ModelsDevMetadataFilter::refreshCatalogProviders($providers, $modelsDev);

        return [
            'ai' => [
                'providers' => $refreshed['providers'],
            ],
        ];
    }

    /**
     * Discovery hints only (upstream model ids absent from yaml). Used by providers:update.
     *
     * @param array<string, mixed> $modelsDevFiltered
     *
     * @return array<string, list<string>>
     */
    public function discoveryHints(string $catalogPath, array $modelsDevFiltered): array
    {
        $providers = $this->catalogLoader->loadProviders($catalogPath);
        $refreshed = ModelsDevMetadataFilter::refreshCatalogProviders($providers, $modelsDevFiltered);

        return $refreshed['discovery'];
    }
}
