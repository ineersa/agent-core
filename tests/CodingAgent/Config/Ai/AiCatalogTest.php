<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config\Ai;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
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
        TestDirectoryIsolation::ensureDirectory($this->homeDir);
        $this->catalogPath = $this->tmpDir.'/ai-catalog.yaml';

        file_put_contents($this->catalogPath, <<<'YAML'
version: 2
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

    public function testBootstrapCopiesBundledCatalogToUserPath(): void
    {
        $userPath = $this->homeDir.'/.hatfield/ai-catalog.yaml';
        $this->assertFileDoesNotExist($userPath);

        $ai = AiConfig::fromArray($this->catalog()->loadProviders()['ai']);

        $this->assertFileExists($userPath);
        $this->assertSame('0600', substr(\sprintf('%o', fileperms($userPath)), -4));
        $this->assertArrayHasKey('glm-5.3', $ai->providers['zai']->models);
        $this->assertSame(1000000, $ai->providers['zai']->models['glm-5.3']->contextWindow);
        $this->assertSame('https://api.z.ai/api/coding/paas/v4', $ai->providers['zai']->baseUrl);
    }

    public function testBundledCatalogIncludesCurrentPiModels(): void
    {
        $catalog = new AiCatalog(
            \dirname(__DIR__, 4).'/config/ai-catalog.yaml',
            $this->homeDir,
        );
        $ai = AiConfig::fromArray($catalog->loadProviders()['ai']);

        $glm = $ai->providers['zai']->models['glm-5.3'];
        $this->assertSame(
            ['minimal' => null, 'low' => 'low', 'medium' => null, 'high' => 'high', 'xhigh' => null, 'max' => 'max'],
            $glm->thinkingLevelMap,
        );
        $this->assertTrue($glm->compatibility?->supportsReasoningEffort);
        $this->assertTrue($glm->compatibility?->zaiToolStream);

        $glmFlash = $ai->providers['zai']->models['glm-5.3-flash'];
        $this->assertSame(['text', 'image', 'video', 'pdf'], $glmFlash->input);
        $this->assertSame(1000000, $glmFlash->contextWindow);
        $this->assertSame(131072, $glmFlash->maxTokens);
        $this->assertSame($glm->thinkingLevelMap, $glmFlash->thinkingLevelMap);
        $this->assertTrue($glmFlash->compatibility?->supportsReasoningEffort);
        $this->assertTrue($glmFlash->compatibility?->zaiToolStream);

        $astra = $ai->providers['openai-codex']->models['gpt-6-astra'];
        $this->assertSame(272000, $astra->contextWindow);
        $this->assertSame(128000, $astra->maxTokens);
        $this->assertSame(
            ['minimal' => null, 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'],
            $astra->thinkingLevelMap,
        );
        $this->assertTrue($astra->compatibility?->supportsReasoningConfigurationUpdates);
        $this->assertTrue($astra->compatibility?->pinContextWindow);

        $sol = $ai->providers['openai-codex']->models['gpt-6-sol'];
        $this->assertSame(272000, $sol->contextWindow);
        $this->assertSame(128000, $sol->maxTokens);
        $this->assertSame(['text', 'image'], $sol->input);
        $this->assertSame(
            ['off' => 'none', 'minimal' => 'low', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'xhigh', 'max' => 'max'],
            $sol->thinkingLevelMap,
        );
        $this->assertTrue($sol->compatibility?->supportsReasoningConfigurationUpdates);
        $this->assertTrue($sol->compatibility?->pinContextWindow);
        $this->assertTrue($sol->toolCalling);
        $this->assertTrue($sol->reasoning);
        $this->assertSame(2.0, $sol->cost?->input);
        $this->assertSame(10.0, $sol->cost?->output);
        $this->assertSame(0.2, $sol->cost?->cacheRead);
        $this->assertSame(2.5, $sol->cost?->cacheWrite);

        $luna = $ai->providers['openai-codex']->models['gpt-6-luna'];
        $this->assertSame(272000, $luna->contextWindow);
        $this->assertSame(128000, $luna->maxTokens);
        $this->assertSame(['text', 'image'], $luna->input);
        $this->assertSame($sol->thinkingLevelMap, $luna->thinkingLevelMap);
        $this->assertTrue($luna->compatibility?->supportsReasoningConfigurationUpdates);
        $this->assertTrue($luna->compatibility?->pinContextWindow);
        $this->assertTrue($luna->toolCalling);
        $this->assertTrue($luna->reasoning);
        $this->assertSame(0.1, $luna->cost?->input);
        $this->assertSame(0.5, $luna->cost?->output);
        $this->assertSame(0.01, $luna->cost?->cacheRead);
        $this->assertSame(0.125, $luna->cost?->cacheWrite);
    }

    public function testBundledOpenCodeGoUsesApiKeyAndSelectedModels(): void
    {
        $catalog = new AiCatalog(\dirname(__DIR__, 4).'/config/ai-catalog.yaml', $this->homeDir);
        $source = $catalog->readBundledCatalog();
        $this->assertNotNull($source);
        $this->assertSame('apikey', $source['providers']['opencode-go']['kind']);
        $this->assertSame('openai-completions', $source['providers']['opencode-go']['api']);

        $ai = AiConfig::fromArray($catalog->loadProviders()['ai']);
        $go = $ai->providers['opencode-go'];
        $this->assertFalse($go->enabled);
        $this->assertSame('opencode-go', $go->type);
        $this->assertSame('https://opencode.ai/zen/go/v1', $go->baseUrl);
        $this->assertSame('/chat/completions', $go->completionsPath);
        $this->assertFalse($go->supportsThinkingLevels);
        $this->assertFalse($go->compatibility?->supportsReasoningEffort);
        $this->assertSame(
            ['deepseek-v4.1-flash', 'muse-spark-1.3-contributor', 'space-bunny-free', 'longcat-2.5-preview-free'],
            array_keys($go->models),
        );
        $this->assertTrue($go->models['deepseek-v4.1-flash']->toolCalling);
        $this->assertSame(1000000, $go->models['deepseek-v4.1-flash']->contextWindow);
    }

    public function testCorruptUserCopyFallsBackToBundled(): void
    {
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        file_put_contents($this->homeDir.'/.hatfield/ai-catalog.yaml', '{not: yaml: [');

        $ai = AiConfig::fromArray($this->catalog()->loadProviders()['ai']);
        $this->assertSame(1000000, $ai->providers['zai']->models['glm-5.3']->contextWindow);
        $this->assertSame('https://api.z.ai/api/coding/paas/v4', $ai->providers['zai']->baseUrl);
    }

    public function testBundledNewerThanUserLogsWarning(): void
    {
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        file_put_contents($this->homeDir.'/.hatfield/ai-catalog.yaml', <<<'YAML'
version: 1
providers:
    zai:
        type: generic
        enabled: false
        base_url: https://api.z.ai/api/coding/paas/v4
        api: openai-completions
        models:
            glm-5.3:
                name: GLM 5.3
                context_window: 1000000
                max_tokens: 131072
                input: [text]
                tool_calling: true
                reasoning: true
YAML);

        $logger = new TestLogger();
        $this->catalog($logger)->loadProviders();

        $warnings = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => 'warning' === $r['level'],
        ));
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('`hatfield providers:update`', (string) $warnings[0]['message']);
        $this->assertStringContainsString('version 2', (string) $warnings[0]['message']);
    }

    public function testSameOrNewerUserVersionIsSilent(): void
    {
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        file_put_contents($this->homeDir.'/.hatfield/ai-catalog.yaml', <<<'YAML'
version: 2
providers:
    zai:
        type: generic
        enabled: false
        base_url: https://api.z.ai/api/coding/paas/v4
        api: openai-completions
        models:
            glm-5.3:
                name: GLM 5.3
                context_window: 1000000
                max_tokens: 131072
                input: [text]
                tool_calling: true
                reasoning: true
YAML);

        $logger = new TestLogger();
        $this->catalog($logger)->loadProviders();
        $warnings = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => 'warning' === $r['level'],
        ));
        $this->assertSame([], $warnings);
    }

    public function testIsBundledNewerThanUserQuery(): void
    {
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        file_put_contents($this->homeDir.'/.hatfield/ai-catalog.yaml', <<<'YAML'
version: 1
providers:
    zai:
        type: generic
        enabled: false
        base_url: https://api.z.ai/api/coding/paas/v4
        api: openai-completions
        models:
            glm-5.3:
                name: GLM 5.3
                context_window: 1000000
                max_tokens: 131072
                input: [text]
                tool_calling: true
                reasoning: true
YAML);

        $catalog = $this->catalog();
        $this->assertTrue($catalog->isBundledNewerThanUser());

        file_put_contents($this->homeDir.'/.hatfield/ai-catalog.yaml', str_replace(
            'version: 1',
            'version: 2',
            (string) file_get_contents($this->homeDir.'/.hatfield/ai-catalog.yaml'),
        ));
        $this->assertFalse($catalog->isBundledNewerThanUser());
    }

    public function testLoaderMergePrecedenceScalarWinWholesaleModelsUnknownPassthrough(): void
    {
        $defaultsPath = $this->tmpDir.'/defaults.yaml';
        file_put_contents($defaultsPath, "tui:\n    theme: cyberpunk\n");

        $cwd = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($cwd.'/.hatfield');
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');

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

    private function catalog(?TestLogger $logger = null): AiCatalog
    {
        return new AiCatalog($this->catalogPath, $this->homeDir, $logger);
    }
}
