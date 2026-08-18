<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class AiCatalogTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private string $catalogPath;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('ai_catalog');
        $this->homeDir = $this->tmpDir.'/home';
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield/cache');
        $this->catalogPath = $this->tmpDir.'/ai-catalog.yaml';

        file_put_contents($this->catalogPath, <<<'YAML'
providers:
    zai:
        label: 'Z.ai'
        kind: apikey
        type: generic
        enabled: false
        base_url: https://api.z.ai/api/coding/paas/v4
        api: openai-completions
        completions_path: /chat/completions
        auth_command: null
        models:
            glm-5.3:
                name: GLM 5.3
                context_window: 1000000
                max_tokens: 131072
                input: [text]
                tool_calling: true
                reasoning: true
                thinking_level_map: { minimal: enabled }
                cost: { input: 0, output: 0 }
    deepseek:
        type: generic
        enabled: false
        base_url: https://api.deepseek.com
        api: openai-completions
        models:
            deepseek-v4-flash:
                name: DeepSeek V4 Flash
                context_window: 1000000
                max_tokens: 384000
                input: [text]
                tool_calling: true
                reasoning: true
                cost: { input: 0.14, output: 0.28 }
YAML);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testYamlModelsResolveWhenCacheAbsent(): void
    {
        $ai = AiConfig::fromArray($this->catalog()->loadProviders()['ai']);

        $this->assertArrayHasKey('glm-5.3', $ai->providers['zai']->models);
        $this->assertSame(1000000, $ai->providers['zai']->models['glm-5.3']->contextWindow);
        $this->assertSame('https://api.z.ai/api/coding/paas/v4', $ai->providers['zai']->baseUrl);
        $this->assertArrayHasKey('deepseek-v4-flash', $ai->providers['deepseek']->models);
    }

    public function testCorruptCacheFallsBackToYamlOnly(): void
    {
        file_put_contents($this->homeDir.'/.hatfield/cache/models-dev.json', '{not-json');

        $ai = AiConfig::fromArray($this->catalog()->loadProviders()['ai']);
        $this->assertSame(1000000, $ai->providers['zai']->models['glm-5.3']->contextWindow);
        $this->assertSame('https://api.z.ai/api/coding/paas/v4', $ai->providers['zai']->baseUrl);
    }

    public function testCacheRefreshesAllowlistedMetadataOnly(): void
    {
        file_put_contents($this->homeDir.'/.hatfield/cache/models-dev.json', json_encode([
            'zai' => [
                'api' => 'https://should-not-leak.example',
                'models' => [
                    'glm-5.3' => [
                        'limit' => ['context' => 2000000, 'output' => 64000],
                        'modalities' => ['input' => ['text', 'audio', 'image']],
                        'reasoning' => true,
                        'tool_call' => true,
                        'cost' => ['input' => 1.1, 'output' => 2.2],
                        'api' => 'https://attacker.example/model',
                        'base_url' => 'https://attacker.example',
                    ],
                    'glm-future' => ['limit' => ['context' => 1]],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $layer = $this->catalog()->loadProviders();
        $provider = AiProviderConfig::fromArray($layer['ai']['providers']['zai'], 'zai');

        $this->assertSame('https://api.z.ai/api/coding/paas/v4', $provider->baseUrl);
        $this->assertSame('openai-completions', $provider->api);
        $this->assertSame('/chat/completions', $provider->completionsPath);
        $this->assertSame(['glm-5.3'], array_keys($provider->models));
        $this->assertSame(2000000, $provider->models['glm-5.3']->contextWindow);
        $this->assertSame(64000, $provider->models['glm-5.3']->maxTokens);
        $this->assertSame(['text', 'image'], $provider->models['glm-5.3']->input);
        $this->assertSame(1.1, $provider->models['glm-5.3']->cost?->input);
        $this->assertSame(['minimal' => 'enabled'], $provider->models['glm-5.3']->thinkingLevelMap);
        $this->assertSame(['glm-future'], $this->catalog()->discoveryHints(json_decode(
            (string) file_get_contents($this->homeDir.'/.hatfield/cache/models-dev.json'),
            true,
            512,
            \JSON_THROW_ON_ERROR,
        ))['zai']);
    }

    public function testFilterUpstreamProvidersKeepsMappedIdsOnly(): void
    {
        $filtered = $this->catalog()->filterUpstreamProviders([
            'zai' => ['models' => []],
            'anthropic' => ['models' => []],
            'xai' => ['models' => []],
        ]);

        $this->assertSame(['zai', 'xai'], array_keys($filtered));
    }

    public function testLoaderMergePrecedenceScalarWinWholesaleModelsUnknownPassthrough(): void
    {
        $defaultsPath = $this->tmpDir.'/defaults.yaml';
        file_put_contents($defaultsPath, "tui:\n    theme: cyberpunk\n");

        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd.'/.hatfield');

        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', <<<'YAML'
ai:
    providers:
        zai:
            enabled: true
            api_key: env:ZAI_API_KEY
            models:
                glm-5.3:
                    name: Pinned GLM
                    context_window: 111
                    max_tokens: 222
                    input: [text]
                    tool_calling: true
                    reasoning: true
        custom-runpod:
            type: generic
            enabled: true
            base_url: https://runpod.example/v1
            api: openai-completions
            models:
                my-model:
                    name: My Model
                    context_window: 8192
                    max_tokens: 2048
                    input: [text]
YAML);

        file_put_contents($cwd.'/.hatfield/settings.yaml', <<<'YAML'
ai:
    providers:
        zai:
            base_url: https://project-override.example
YAML);

        $loader = new AppConfigLoader(
            new SettingsPathResolver(appRoot: '/app', homeDir: $this->homeDir),
            aiCatalog: $this->catalog(),
        );
        $effective = $loader->load($defaultsPath, $cwd)->effective;
        $ai = AiConfig::fromArray($effective['ai']);

        $this->assertTrue($ai->providers['zai']->enabled);
        $this->assertSame('env:ZAI_API_KEY', $ai->providers['zai']->apiKey);
        $this->assertSame('https://project-override.example', $ai->providers['zai']->baseUrl);
        $this->assertSame(['glm-5.3'], array_keys($ai->providers['zai']->models));
        $this->assertSame('Pinned GLM', $ai->providers['zai']->models['glm-5.3']->name);
        $this->assertSame(111, $ai->providers['zai']->models['glm-5.3']->contextWindow);
        $this->assertArrayHasKey('deepseek', $ai->providers);
        $this->assertFalse($ai->providers['deepseek']->enabled);
        $this->assertArrayHasKey('custom-runpod', $ai->providers);
        $this->assertSame('https://runpod.example/v1', $ai->providers['custom-runpod']->baseUrl);
        $this->assertSame(['my-model'], array_keys($ai->providers['custom-runpod']->models));
    }

    private function catalog(): AiCatalog
    {
        return new AiCatalog($this->catalogPath, $this->homeDir);
    }
}
