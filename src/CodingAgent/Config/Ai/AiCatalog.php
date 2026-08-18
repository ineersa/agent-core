<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

use Symfony\Component\Yaml\Yaml;

/**
 * Curated AI provider catalog + optional models.dev metadata overlay.
 *
 * YAML ({@see config/ai-catalog.yaml}) is authoritative for which models exist.
 * ~/.hatfield/cache/models-dev.json (written by providers:update) may refresh
 * allowlisted model metadata only. Connection settings never come from models.dev.
 */
final class AiCatalog
{
    /**
     * Hatfield provider id → models.dev provider id.
     *
     * @var array<string, string>
     */
    public const PROVIDER_ID_MAP = [
        'zai' => 'zai',
        'deepseek' => 'deepseek',
        'openai-codex' => 'openai',
        'grok-cli' => 'xai',
    ];

    public const CACHE_RELATIVE_PATH = '.hatfield/cache/models-dev.json';

    public function __construct(
        private readonly string $catalogPath,
        private readonly string $homeDir,
    ) {
    }

    /**
     * Settings-shaped layer: `{ ai: { providers: { ... } } }`, or [] when catalog absent.
     *
     * @return array<string, mixed>
     */
    public function loadProviders(): array
    {
        $providers = $this->parseYamlProviders();
        if ([] === $providers) {
            return [];
        }

        return [
            'ai' => [
                'providers' => $this->applyMetadata($providers, $this->readCache())['providers'],
            ],
        ];
    }

    /**
     * Keep only mapped upstream provider objects for the on-disk cache.
     * Apply-time allowlist still strips connection fields.
     *
     * @param array<string, mixed> $raw
     *
     * @return array<string, mixed>
     */
    public function filterUpstreamProviders(array $raw): array
    {
        $out = [];
        foreach (array_values(self::PROVIDER_ID_MAP) as $id) {
            if (isset($raw[$id]) && \is_array($raw[$id])) {
                $out[$id] = $raw[$id];
            }
        }

        return $out;
    }

    /**
     * Upstream model ids present in filtered models.dev data but absent from yaml.
     *
     * @param array<string, mixed> $upstreamFiltered
     *
     * @return array<string, list<string>>
     */
    public function discoveryHints(array $upstreamFiltered): array
    {
        return $this->applyMetadata($this->parseYamlProviders(), $upstreamFiltered)['discovery'];
    }

    public function cachePath(): string
    {
        return rtrim($this->homeDir, '/').'/'.self::CACHE_RELATIVE_PATH;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseYamlProviders(): array
    {
        if (!is_readable($this->catalogPath)) {
            return [];
        }
        $content = file_get_contents($this->catalogPath);
        if (false === $content || '' === trim($content)) {
            return [];
        }
        $data = Yaml::parse($content);
        if (!\is_array($data)) {
            return [];
        }
        $providers = $data['providers'] ?? null;

        return \is_array($providers) ? $providers : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function readCache(): array
    {
        $path = $this->cachePath();
        if (!is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if (false === $raw || '' === trim($raw)) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * Overlay allowlisted metadata onto yaml model entries; never adds/removes models.
     *
     * @param array<string, mixed> $catalogProviders
     * @param array<string, mixed> $upstreamFiltered
     *
     * @return array{providers: array<string, mixed>, discovery: array<string, list<string>>}
     */
    private function applyMetadata(array $catalogProviders, array $upstreamFiltered): array
    {
        $discovery = [];
        $out = [];

        foreach ($catalogProviders as $hatfieldId => $provider) {
            if (!\is_string($hatfieldId) || !\is_array($provider)) {
                continue;
            }

            $upstreamId = self::PROVIDER_ID_MAP[$hatfieldId] ?? null;
            $upstream = null !== $upstreamId && isset($upstreamFiltered[$upstreamId]) && \is_array($upstreamFiltered[$upstreamId])
                ? $upstreamFiltered[$upstreamId]
                : null;
            $upstreamModels = \is_array($upstream['models'] ?? null) ? $upstream['models'] : [];
            $catalogModels = \is_array($provider['models'] ?? null) ? $provider['models'] : [];

            if ([] !== $upstreamModels) {
                $missing = [];
                foreach (array_keys($upstreamModels) as $upstreamModelId) {
                    if (\is_string($upstreamModelId) && '' !== $upstreamModelId && !\array_key_exists($upstreamModelId, $catalogModels)) {
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
                    $meta = $this->extractModelMetadata($upstreamModel);
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
            unset($provider['label'], $provider['kind'], $provider['auth_command']);
            $out[$hatfieldId] = $provider;
        }

        return ['providers' => $out, 'discovery' => $discovery];
    }

    /**
     * Map one models.dev model object to Hatfield fields (allowlist only).
     * SECURITY: never copies api/base_url/paths/auth.
     *
     * @param array<string, mixed> $upstreamModel
     *
     * @return array<string, mixed>
     */
    private function extractModelMetadata(array $upstreamModel): array
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
                if (\is_string($modality) && ('text' === $modality || 'image' === $modality)) {
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
            $mapped = [];
            foreach (['input', 'output', 'cache_read', 'cache_write'] as $field) {
                if (isset($cost[$field]) && is_numeric($cost[$field])) {
                    $mapped[$field] = (float) $cost[$field];
                }
            }
            if ([] !== $mapped) {
                $out['cost'] = $mapped;
            }
        }

        return $out;
    }
}
