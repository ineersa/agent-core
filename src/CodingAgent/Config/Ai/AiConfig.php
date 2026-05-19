<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

/**
 * Top-level AI configuration from Hatfield settings.
 *
 * Produced by {@see AppConfig} when the ai section is present in settings.
 */
final readonly class AiConfig
{
    /**
     * @param string|null                     $defaultModel     Default model in provider/model format
     * @param string|null                     $defaultReasoning Default reasoning level
     * @param array<string, AiProviderConfig> $providers        Enabled providers keyed by provider ID
     * @param list<string>                    $favoriteModels   Favorited provider/model strings
     */
    public function __construct(
        public ?string $defaultModel = null,
        public ?string $defaultReasoning = null,
        public array $providers = [],
        public array $favoriteModels = [],
    ) {
    }

    /**
     * Returns null when the ai section is absent entirely.
     *
     * @param array<string, mixed> $data Full merged config array
     */
    public static function optionalFromArray(array $data): ?self
    {
        if (!isset($data['ai']) || !\is_array($data['ai'])) {
            return null;
        }

        return self::fromArray($data['ai']);
    }

    /**
     * Create from the ai subsection of config.
     *
     * @param array<string, mixed> $aiData
     */
    public static function fromArray(array $aiData): self
    {
        $providers = [];
        if (isset($aiData['providers']) && \is_array($aiData['providers'])) {
            foreach ($aiData['providers'] as $providerId => $providerData) {
                if (!\is_string($providerId) || '' === $providerId) {
                    throw new \RuntimeException('Provider key must be a non-empty string in AI config.');
                }

                if (!\is_array($providerData)) {
                    throw new \RuntimeException(\sprintf('Provider "%s" must be an associative array in AI config.', $providerId));
                }

                $providers[$providerId] = AiProviderConfig::fromArray($providerData, $providerId);
            }
        }

        $favorites = [];
        if (isset($aiData['favorite_models']) && \is_array($aiData['favorite_models'])) {
            foreach ($aiData['favorite_models'] as $fav) {
                if (\is_string($fav) && '' !== $fav) {
                    $favorites[] = $fav;
                }
            }
        }

        return new self(
            defaultModel: isset($aiData['default_model']) ? (string) $aiData['default_model'] : null,
            defaultReasoning: isset($aiData['default_reasoning']) ? (string) $aiData['default_reasoning'] : null,
            providers: $providers,
            favoriteModels: $favorites,
        );
    }
}
