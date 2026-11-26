<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\SettingsPathResolver;

final class ExtensionAgentCassetteConnection
{
    public const string REPLAY_BASE_URL = 'https://llama-cassette.invalid/v1';
    private const string COMPLETIONS_PATH = '/chat/completions';

    /** @return array{base_url: non-empty-string, api_key: ?string} */
    public static function load(string $root, string $cwd, ?string $home = null): array
    {
        $resources = new AppResourceLocator($root);
        $paths = new SettingsPathResolver($root, $home);
        $catalog = new AiCatalog($resources->getAiCatalogPath(), $paths->getHomeDir(), bootstrapUserCatalog: false);
        $config = (new AppConfigLoader($paths, $catalog))->load($resources->getDefaultsPath(), $cwd)->effective;
        $data = $config['ai']['providers']['llama_cpp'] ?? null;
        if (!\is_array($data)) {
            throw new \RuntimeException('Configure an enabled generic llama_cpp/flash chat provider before recording.');
        }

        $provider = AiProviderConfig::fromArray($data, 'llama_cpp');
        $baseUrl = rtrim($provider->baseUrl, '/');
        // The model key, not the display name, is the model sent on the wire.
        if (!$provider->enabled || 'generic' !== $provider->type || !$provider->supportsCompletions || !isset($provider->models['flash']) || '' === $baseUrl) {
            throw new \RuntimeException('Configure an enabled generic llama_cpp/flash chat provider before recording.');
        }
        if (self::COMPLETIONS_PATH !== $provider->completionsPath) {
            throw new \RuntimeException('Cassette recording requires llama_cpp completions_path: '.self::COMPLETIONS_PATH.'; refusing to send the scenario to a different route.');
        }

        return ['base_url' => $baseUrl, 'api_key' => $provider->apiKey];
    }

    /**
     * Both modes use the same request definition; capture imports only the transport.
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, mixed>
     */
    public static function scenarioProvider(array $connection): array
    {
        $provider = [
            'type' => 'generic',
            'enabled' => true,
            'base_url' => self::REPLAY_BASE_URL,
            'api' => 'openai-completions',
            'completions_path' => self::COMPLETIONS_PATH,
            'supports_completions' => true,
            'supports_thinking_levels' => true,
            'compatibility' => ['thinking_format' => 'llama_cpp'],
            'models' => [
                'flash' => [
                    'name' => 'flash',
                    'context_window' => 262144,
                    'max_tokens' => 65536,
                    'input' => ['text', 'image'],
                    'tool_calling' => true,
                    'reasoning' => true,
                    'thinking_level_map' => ['off' => 'none'],
                    'compatibility' => ['thinking_format' => 'mistral', 'supports_reasoning_effort' => true],
                ],
            ],
        ];

        return array_replace($provider, array_intersect_key($connection, ['base_url' => true, 'api_key' => true]));
    }
}
