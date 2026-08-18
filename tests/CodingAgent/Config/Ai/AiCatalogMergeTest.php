<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\CodingAgent\Config\Ai\AiCatalogMerge;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\ModelsDevCache;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class AiCatalogMergeTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private string $catalogPath;
    private string $snapshotPath;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('ai_catalog_merge');
        $this->homeDir = $this->tmpDir.'/home';
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield/cache');

        $this->catalogPath = $this->tmpDir.'/ai-catalog.yaml';
        $this->snapshotPath = $this->tmpDir.'/models-dev.snapshot.json';

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

        file_put_contents($this->snapshotPath, json_encode([
            'zai' => [
                'api' => 'https://should-not-leak.example',
                'models' => [
                    'glm-5.3' => [
                        'limit' => ['context' => 2000000, 'output' => 64000],
                        'cost' => ['input' => 1.1, 'output' => 2.2],
                    ],
                    'glm-future' => ['limit' => ['context' => 1]],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testYamlModelsResolveWhenCacheAndSnapshotAbsent(): void
    {
        $merge = new AiCatalogMerge();
        $layer = $merge->buildLayer($this->catalogPath, new ModelsDevCache($this->homeDir, $this->tmpDir.'/missing-snapshot.json'));
        $ai = AiConfig::fromArray($layer['ai']);

        $this->assertArrayHasKey('glm-5.3', $ai->providers['zai']->models);
        $this->assertSame(1000000, $ai->providers['zai']->models['glm-5.3']->contextWindow);
        $this->assertSame('https://api.z.ai/api/coding/paas/v4', $ai->providers['zai']->baseUrl);
    }

    public function testSnapshotRefreshesMetadataOnlyForYamlIds(): void
    {
        $merge = new AiCatalogMerge();
        $layer = $merge->buildLayer($this->catalogPath, new ModelsDevCache($this->homeDir, $this->snapshotPath));
        $ai = AiConfig::fromArray($layer['ai']);

        $this->assertSame(2000000, $ai->providers['zai']->models['glm-5.3']->contextWindow);
        $this->assertSame(64000, $ai->providers['zai']->models['glm-5.3']->maxTokens);
        $this->assertSame(1.1, $ai->providers['zai']->models['glm-5.3']->cost?->input);
        $this->assertSame(['minimal' => 'enabled'], $ai->providers['zai']->models['glm-5.3']->thinkingLevelMap);
        $this->assertArrayNotHasKey('glm-future', $ai->providers['zai']->models);
        $this->assertSame('https://api.z.ai/api/coding/paas/v4', $ai->providers['zai']->baseUrl);
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
            aiCatalogPath: $this->catalogPath,
            modelsDevSnapshotPath: $this->snapshotPath,
        );
        $effective = $loader->load($defaultsPath, $cwd)->effective;
        $ai = AiConfig::fromArray($effective['ai']);

        $this->assertTrue($ai->providers['zai']->enabled);
        $this->assertSame('env:ZAI_API_KEY', $ai->providers['zai']->apiKey);
        $this->assertSame('https://project-override.example', $ai->providers['zai']->baseUrl);
        // Wholesale models replace from user layer (project did not set models).
        $this->assertSame(['glm-5.3'], array_keys($ai->providers['zai']->models));
        $this->assertSame('Pinned GLM', $ai->providers['zai']->models['glm-5.3']->name);
        $this->assertSame(111, $ai->providers['zai']->models['glm-5.3']->contextWindow);
        // Catalog deepseek remains (no user override) — enabled false from catalog.
        $this->assertArrayHasKey('deepseek', $ai->providers);
        $this->assertFalse($ai->providers['deepseek']->enabled);
        $this->assertArrayHasKey('custom-runpod', $ai->providers);
        $this->assertSame('https://runpod.example/v1', $ai->providers['custom-runpod']->baseUrl);
        $this->assertSame(['my-model'], array_keys($ai->providers['custom-runpod']->models));
    }

    public function testCachePreferredOverSnapshot(): void
    {
        $cachePath = $this->homeDir.'/.hatfield/cache/models-dev.json';
        file_put_contents($cachePath, json_encode([
            'zai' => [
                'models' => [
                    'glm-5.3' => [
                        'limit' => ['context' => 333333, 'output' => 4444],
                    ],
                ],
            ],
        ], \JSON_THROW_ON_ERROR));

        $merge = new AiCatalogMerge();
        $layer = $merge->buildLayer($this->catalogPath, new ModelsDevCache($this->homeDir, $this->snapshotPath));
        $ai = AiConfig::fromArray($layer['ai']);
        $this->assertSame(333333, $ai->providers['zai']->models['glm-5.3']->contextWindow);
        $this->assertSame(4444, $ai->providers['zai']->models['glm-5.3']->maxTokens);
    }
}
