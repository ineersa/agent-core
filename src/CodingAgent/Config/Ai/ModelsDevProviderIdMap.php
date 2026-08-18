<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

/**
 * Maps Hatfield catalog provider ids to models.dev provider ids.
 *
 * Connection settings stay curated in config/ai-catalog.yaml; models.dev is
 * metadata-only for model ids already present in that yaml.
 */
final class ModelsDevProviderIdMap
{
    /**
     * Hatfield provider id → models.dev provider id.
     *
     * @var array<string, string>
     */
    public const HATFIELD_TO_MODELS_DEV = [
        'zai' => 'zai',
        'deepseek' => 'deepseek',
        'grok-cli' => 'xai',
        'openai-codex' => 'openai',
    ];

    /**
     * @return list<string>
     */
    public static function modelsDevIds(): array
    {
        return array_values(self::HATFIELD_TO_MODELS_DEV);
    }

    public static function modelsDevIdFor(string $hatfieldProviderId): ?string
    {
        return self::HATFIELD_TO_MODELS_DEV[$hatfieldProviderId] ?? null;
    }
}
