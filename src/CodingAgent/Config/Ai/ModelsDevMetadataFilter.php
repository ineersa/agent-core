<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

/**
 * Filters models.dev payloads to the allowlisted per-model metadata fields.
 *
 * SECURITY: connection settings (api/base_url/paths/auth) are NEVER read from
 * models.dev. Upstream objects may include an `api` URL field; this filter
 * drops everything outside the model-field allowlist so hostile upstream
 * values cannot redirect where API keys are sent.
 *
 * Cost units: models.dev reports USD per 1M tokens, matching
 * {@see AiCost} ("USD per 1M tokens"). Values are copied as-is.
 */
final class ModelsDevMetadataFilter
{
    /**
     * Upstream model fields that may refresh Hatfield catalog model entries.
     *
     * @var list<string>
     */
    public const MODEL_FIELD_ALLOWLIST = [
        'context_window',
        'max_tokens',
        'input',
        'reasoning',
        'tool_calling',
        'cost',
    ];

    /**
     * Keep only catalog provider ids; preserve whole provider objects for the
     * on-disk cache/snapshot. Runtime apply still runs {@see extractModelMetadata}.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    public static function filterProviders(array $raw): array
    {
        $out = [];
        foreach (ModelsDevProviderIdMap::modelsDevIds() as $id) {
            if (!isset($raw[$id]) || !\is_array($raw[$id])) {
                continue;
            }
            $out[$id] = $raw[$id];
        }

        return $out;
    }

    /**
     * Map one models.dev model object to Hatfield model fields (allowlist only).
     *
     * @param array<string, mixed> $upstreamModel
     *
     * @return array<string, mixed>
     */
    public static function extractModelMetadata(array $upstreamModel): array
    {
        $out = [];

        $limit = $upstreamModel['limit'] ?? null;
        if (\is_array($limit)) {
            if (isset($limit['context']) && is_numeric($limit['context'])) {
                $out['context_window'] = (int) $limit['context'];
            }
            if (isset($limit['output']) && is_numeric($limit['output'])) {
                $out['max_tokens'] = (int) $limit['output'];
            }
        }

        $modalities = $upstreamModel['modalities'] ?? null;
        if (\is_array($modalities) && isset($modalities['input']) && \is_array($modalities['input'])) {
            $input = [];
            foreach ($modalities['input'] as $modality) {
                if (!\is_string($modality)) {
                    continue;
                }
                if ('text' === $modality || 'image' === $modality) {
                    $input[] = $modality;
                }
            }
            if ([] !== $input) {
                $out['input'] = array_values(array_unique($input));
            }
        }

        if (\array_key_exists('reasoning', $upstreamModel)) {
            $out['reasoning'] = (bool) $upstreamModel['reasoning'];
        }

        if (\array_key_exists('tool_call', $upstreamModel)) {
            $out['tool_calling'] = (bool) $upstreamModel['tool_call'];
        }

        $cost = $upstreamModel['cost'] ?? null;
        if (\is_array($cost)) {
            // models.dev cost.* is USD per 1M tokens — same unit as AiCost.
            $mapped = [];
            if (isset($cost['input']) && is_numeric($cost['input'])) {
                $mapped['input'] = (float) $cost['input'];
            }
            if (isset($cost['output']) && is_numeric($cost['output'])) {
                $mapped['output'] = (float) $cost['output'];
            }
            if (isset($cost['cache_read']) && is_numeric($cost['cache_read'])) {
                $mapped['cache_read'] = (float) $cost['cache_read'];
            }
            if (isset($cost['cache_write']) && is_numeric($cost['cache_write'])) {
                $mapped['cache_write'] = (float) $cost['cache_write'];
            }
            if ([] !== $mapped) {
                $out['cost'] = $mapped;
            }
        }

        return $out;
    }

    /**
     * Apply allowlisted metadata onto yaml model entries for ids present in both.
     *
     * Never adds/removes models. Never touches thinking_level_map, compatibility,
     * name, or connection-level provider fields.
     *
     * @param array<string, mixed> $catalogProviders  providers map from ai-catalog.yaml
     * @param array<string, mixed> $modelsDevFiltered filtered models.dev providers keyed by models.dev id
     *
     * @return array{providers: array<string, mixed>, discovery: array<string, list<string>>}
     */
    public static function refreshCatalogProviders(array $catalogProviders, array $modelsDevFiltered): array
    {
        $discovery = [];
        $out = [];

        foreach ($catalogProviders as $hatfieldId => $provider) {
            if (!\is_string($hatfieldId) || !\is_array($provider)) {
                continue;
            }

            $modelsDevId = ModelsDevProviderIdMap::modelsDevIdFor($hatfieldId);
            $upstream = null !== $modelsDevId && isset($modelsDevFiltered[$modelsDevId]) && \is_array($modelsDevFiltered[$modelsDevId])
                ? $modelsDevFiltered[$modelsDevId]
                : null;

            $upstreamModels = \is_array($upstream['models'] ?? null) ? $upstream['models'] : [];
            $catalogModels = \is_array($provider['models'] ?? null) ? $provider['models'] : [];

            if ([] !== $upstreamModels) {
                $missing = [];
                foreach (array_keys($upstreamModels) as $upstreamModelId) {
                    if (!\is_string($upstreamModelId) || '' === $upstreamModelId) {
                        continue;
                    }
                    if (!\array_key_exists($upstreamModelId, $catalogModels)) {
                        $missing[] = $upstreamModelId;
                    }
                }
                if ([] !== $missing) {
                    sort($missing);
                    $discovery[$hatfieldId] = $missing;
                }
            }

            $refreshedModels = [];
            foreach ($catalogModels as $modelId => $modelData) {
                if (!\is_string($modelId) || !\is_array($modelData)) {
                    continue;
                }
                $upstreamModel = $upstreamModels[$modelId] ?? null;
                if (\is_array($upstreamModel)) {
                    $meta = self::extractModelMetadata($upstreamModel);
                    // Metadata refresh overlays yaml scalars; thinking_level_map / compatibility stay curated.
                    foreach ($meta as $key => $value) {
                        if ('cost' === $key && \is_array($value) && isset($modelData['cost']) && \is_array($modelData['cost'])) {
                            $modelData['cost'] = array_merge($modelData['cost'], $value);
                        } else {
                            $modelData[$key] = $value;
                        }
                    }
                }
                $refreshedModels[$modelId] = $modelData;
            }

            $provider['models'] = $refreshedModels;
            // Strip catalog-only helper keys before AiProviderConfig::fromArray.
            unset($provider['label'], $provider['kind'], $provider['auth_command']);
            $out[$hatfieldId] = $provider;
        }

        return ['providers' => $out, 'discovery' => $discovery];
    }
}
