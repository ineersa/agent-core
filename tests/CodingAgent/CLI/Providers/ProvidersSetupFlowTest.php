<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Providers;

use Ineersa\CodingAgent\CLI\Providers\ProvidersSetupFlow;
use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Yaml\Yaml;

/**
 * Thesis: ProvidersSetupFlow owns sparse known-provider writes (never models:),
 * OAuth enable + auth-hint tracking, custom full definitions, collision rejection,
 * disable semantics, and --project layer targeting — headless, no console I/O.
 */
#[CoversClass(ProvidersSetupFlow::class)]
final class ProvidersSetupFlowTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private string $projectDir;
    private string $catalogPath;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('providers_setup_flow');
        $this->homeDir = $this->tmpDir.'/home';
        $this->projectDir = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        TestDirectoryIsolation::ensureDirectory($this->projectDir.'/.hatfield');
        $this->catalogPath = $this->tmpDir.'/ai-catalog.yaml';

        file_put_contents($this->catalogPath, <<<'YAML'
version: 1
providers:
    zai:
        label: 'Z.ai (GLM)'
        kind: apikey
        type: generic
        enabled: false
        base_url: https://api.z.ai/example
        api: openai-completions
        completions_path: /chat/completions
        auth_command: null
        models:
            glm-5.3:
                name: GLM 5.3
                context_window: 200000
                max_tokens: 131072
                input: [text]
                tool_calling: true
                reasoning: true
                cost: { input: 0, output: 0 }
    deepseek:
        label: 'DeepSeek'
        kind: apikey
        type: generic
        enabled: false
        base_url: https://api.deepseek.com
        api: openai-completions
        completions_path: /chat/completions
        auth_command: null
        models:
            deepseek-v4-pro:
                name: DeepSeek V4 Pro
                context_window: 1000000
                max_tokens: 384000
                input: [text]
                tool_calling: true
                reasoning: true
                cost: { input: 0, output: 0 }
    openai-codex:
        label: 'OpenAI Codex'
        kind: oauth
        type: codex
        enabled: false
        base_url: https://chatgpt.com/backend-api
        api: openai-responses
        completions_path: /codex/responses
        auth_command: 'auth:codex'
        models:
            gpt-5.6-luna:
                name: GPT-5.6 Luna
                context_window: 272000
                max_tokens: 128000
                input: [text, image]
                tool_calling: true
                reasoning: true
                cost: { input: 1, output: 6 }
    grok-cli:
        label: 'Grok / xAI'
        kind: oauth
        type: grok
        enabled: false
        base_url: https://cli-chat-proxy.grok.com
        api: openai-responses
        completions_path: /v1/responses
        auth_command: 'auth:grok'
        models:
            grok-composer-2.5-fast:
                name: Composer 2.5 Fast
                context_window: 200000
                max_tokens: 30000
                input: [text, image]
                tool_calling: true
                reasoning: false
                cost: { input: 3, output: 15 }
YAML);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    #[Test]
    public function presetEnvKeyWritesSparseUserSettingsWithoutModels(): void
    {
        $flow = $this->createFlow();
        $flow->enableApiKey('zai', $flow->formatEnvApiKey('ZAI_API_KEY'));
        $flow->setDefaultModel('zai/glm-5.3');

        $settings = $this->parseUserSettings();
        $this->assertSame([
            'enabled' => true,
            'api_key' => 'env:ZAI_API_KEY',
        ], $settings['ai']['providers']['zai'] ?? null);
        $this->assertArrayNotHasKey('models', $settings['ai']['providers']['zai']);
        $this->assertArrayNotHasKey('base_url', $settings['ai']['providers']['zai']);
        $this->assertSame('zai/glm-5.3', $settings['ai']['default_model'] ?? null);
        $this->assertTrue($flow->wroteSomething());
        $this->assertStringContainsString('/.hatfield/settings.yaml', $flow->settingsPath());
    }

    #[Test]
    public function presetRawKeyWritesSparseApiKey(): void
    {
        $flow = $this->createFlow();
        $flow->enableApiKey('deepseek', 'sk-test-raw-key');

        $settings = $this->parseUserSettings();
        $provider = $settings['ai']['providers']['deepseek'] ?? null;
        $this->assertIsArray($provider);
        $this->assertTrue($provider['enabled']);
        $this->assertSame('sk-test-raw-key', $provider['api_key']);
        $this->assertArrayNotHasKey('models', $provider);
        $this->assertArrayNotHasKey('default_model', $settings['ai'] ?? []);
    }

    #[Test]
    public function oauthFlowWritesEnabledOnlyAndTracksAuthHint(): void
    {
        $flow = $this->createFlow();
        $flow->enableOauth('grok-cli');

        $this->assertSame(['auth:grok'], $flow->pendingAuthCommands());
        $this->assertContains('grok-cli/grok-composer-2.5-fast', $flow->configuredModelRefs());

        $settings = $this->parseUserSettings();
        $provider = $settings['ai']['providers']['grok-cli'] ?? null;
        $this->assertSame(['enabled' => true], $provider);
        $this->assertArrayNotHasKey('models', $provider);
        $this->assertArrayNotHasKey('api_key', $provider);
    }

    #[Test]
    public function customHappyPathWritesFullDefinition(): void
    {
        $flow = $this->createFlow();
        $flow->saveCustom(
            'local-llm',
            'http://127.0.0.1:8080',
            '/v1/chat/completions',
            null,
            [
                'my-model' => [
                    'name' => 'My Model',
                    'context_window' => 8192,
                    'max_tokens' => 2048,
                    'input' => ['text'],
                    'tool_calling' => true,
                    'reasoning' => false,
                    'thinking_level_map' => [],
                    'cost' => ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0],
                ],
            ],
            false,
            '',
        );
        $flow->setDefaultModel('local-llm/my-model');

        $settings = $this->parseUserSettings();
        $provider = $settings['ai']['providers']['local-llm'] ?? null;
        $this->assertIsArray($provider);
        $this->assertTrue($provider['enabled']);
        $this->assertSame('http://127.0.0.1:8080', $provider['base_url']);
        $this->assertSame('/v1/chat/completions', $provider['completions_path']);
        $this->assertSame('openai-completions', $provider['api']);
        $this->assertArrayHasKey('my-model', $provider['models']);
        $this->assertSame(['text'], $provider['models']['my-model']['input']);
        $this->assertFalse($provider['models']['my-model']['reasoning']);
        $this->assertSame(0, $provider['models']['my-model']['cost']['input']);
        $this->assertFalse($provider['compatibility']['supports_developer_role']);
        $this->assertSame('local-llm/my-model', $settings['ai']['default_model'] ?? null);
    }

    #[Test]
    public function customIdCollidingWithCatalogIsRejected(): void
    {
        $flow = $this->createFlow();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"zai" is built into Hatfield — choose it from the list above instead.');
        $flow->validateCustomId('zai');
    }

    #[Test]
    public function projectLayerWritesProjectSettings(): void
    {
        $flow = $this->createFlow(project: true);
        $flow->enableOauth('openai-codex');

        $this->assertFileDoesNotExist($this->homeDir.'/.hatfield/settings.yaml');
        $projectFile = $this->projectDir.'/.hatfield/settings.yaml';
        $this->assertFileExists($projectFile);
        $settings = Yaml::parseFile($projectFile);
        $this->assertIsArray($settings);
        $this->assertSame(['enabled' => true], $settings['ai']['providers']['openai-codex'] ?? null);
        $this->assertSame(['auth:codex'], $flow->pendingAuthCommands());
        $this->assertStringContainsString($this->projectDir, $flow->settingsPath());
    }

    #[Test]
    public function knownProviderNeverReceivesModelsKeyEvenAcrossMultiplePresets(): void
    {
        $flow = $this->createFlow();
        $flow->enableApiKey('zai', $flow->formatEnvApiKey('ZAI_API_KEY'));
        $flow->enableOauth('grok-cli');

        $rows = $flow->providerRows();
        $zai = null;
        foreach ($rows as $row) {
            if ('zai' === $row['id']) {
                $zai = $row;
            }
        }
        $this->assertNotNull($zai);
        $this->assertSame('✓ enabled', $zai['status']);
        $this->assertSame('needs an API key', $zai['need']);

        $settings = $this->parseUserSettings();
        foreach (['zai', 'grok-cli'] as $id) {
            $this->assertArrayHasKey($id, $settings['ai']['providers']);
            $this->assertArrayNotHasKey('models', $settings['ai']['providers'][$id]);
        }
    }

    #[Test]
    public function disableFlowWritesEnabledFalseWithoutModels(): void
    {
        $flow = $this->createFlow(
            ai: new AiConfig(
                providers: [
                    'grok-cli' => new AiProviderConfig(id: 'grok-cli', type: 'grok', enabled: true),
                ],
            ),
        );
        $this->assertTrue($flow->isEnabled('grok-cli'));
        $flow->disable('grok-cli');

        $this->assertFalse($flow->isEnabled('grok-cli'));
        $this->assertSame([], $flow->pendingAuthCommands());

        $settings = $this->parseUserSettings();
        $provider = $settings['ai']['providers']['grok-cli'] ?? null;
        $this->assertSame(['enabled' => false], $provider);
        $this->assertArrayNotHasKey('models', $provider);
    }

    #[Test]
    public function enableThenDisableSameRunDropsAuthHintAndDefaultChoices(): void
    {
        $flow = $this->createFlow();
        $flow->enableOauth('grok-cli');
        $this->assertSame(['auth:grok'], $flow->pendingAuthCommands());
        $this->assertNotSame([], $flow->configuredModelRefs());

        $flow->disable('grok-cli');

        $this->assertSame([], $flow->pendingAuthCommands());
        $this->assertSame([], $flow->configuredModelRefs());
        $this->assertFalse($flow->isEnabled('grok-cli'));
        $this->assertTrue($flow->wroteSomething());

        $settings = $this->parseUserSettings();
        $this->assertSame(['enabled' => false], $settings['ai']['providers']['grok-cli'] ?? null);
    }

    #[Test]
    public function disablingDefaultModelProviderWarnsWithoutRewriting(): void
    {
        $flow = $this->createFlow(
            ai: new AiConfig(
                defaultModel: 'zai/glm-5.3',
                providers: [
                    'zai' => new AiProviderConfig(id: 'zai', enabled: true),
                ],
            ),
        );
        $warning = $flow->defaultModelWarningFor('zai');
        $this->assertNotNull($warning);
        $this->assertStringContainsString('Your default model "zai/glm-5.3" is now unavailable', $warning);
        $this->assertStringContainsString('Run setup again to pick another', $warning);

        $flow->disable('zai');

        $settings = $this->parseUserSettings();
        $this->assertSame(['enabled' => false], $settings['ai']['providers']['zai'] ?? null);
        $this->assertArrayNotHasKey('default_model', $settings['ai'] ?? []);
    }

    #[Test]
    public function formatEnvApiKeyRejectsInvalidNames(): void
    {
        $flow = $this->createFlow();
        $this->expectException(\InvalidArgumentException::class);
        $flow->formatEnvApiKey('not-valid');
    }

    #[Test]
    public function customProvidersLiveInSubmenuNotMainPicker(): void
    {
        $flow = $this->createFlow();
        $flow->saveCustom(
            'local-llm',
            'http://127.0.0.1:8080',
            '/v1/chat/completions',
            null,
            [
                'my-model' => [
                    'name' => 'My Model',
                    'context_window' => 8192,
                    'max_tokens' => 2048,
                    'input' => ['text'],
                    'tool_calling' => true,
                    'reasoning' => false,
                    'thinking_level_map' => [],
                    'cost' => ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0],
                ],
            ],
            false,
            '',
        );

        foreach ($flow->providerRows() as $row) {
            $this->assertNotSame('local-llm', $row['id']);
            $this->assertNotSame('custom', $row['kind']);
        }

        $customs = $flow->customProviderRows();
        $this->assertCount(1, $customs);
        $this->assertSame('local-llm', $customs[0]['id']);
        $this->assertSame('http://127.0.0.1:8080', $customs[0]['url']);
        $this->assertTrue($customs[0]['enabled']);

        $definition = $flow->customDefinition('local-llm');
        $this->assertNotNull($definition);
        $this->assertSame('http://127.0.0.1:8080', $definition['baseUrl']);
        $this->assertArrayHasKey('my-model', $definition['models']);
    }

    #[Test]
    public function removeCustomDeletesSettingsEntryAndHidesFromSubmenu(): void
    {
        $flow = $this->createFlow();
        $flow->saveCustom(
            'local-llm',
            'http://127.0.0.1:8080',
            '/v1/chat/completions',
            'env:LOCAL_KEY',
            [
                'my-model' => [
                    'name' => 'My Model',
                    'context_window' => 8192,
                    'max_tokens' => 2048,
                    'input' => ['text'],
                    'tool_calling' => true,
                    'reasoning' => false,
                    'thinking_level_map' => [],
                    'cost' => ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0],
                ],
            ],
            false,
            '',
        );
        $this->assertNotSame([], $flow->customProviderRows());

        $flow->removeCustom('local-llm');

        $this->assertSame([], $flow->customProviderRows());
        $this->assertNull($flow->customDefinition('local-llm'));
        $settings = $this->parseUserSettings();
        $this->assertArrayNotHasKey('local-llm', $settings['ai']['providers'] ?? []);
    }

    #[Test]
    public function disableCustomPreservesFullDefinition(): void
    {
        $flow = $this->createFlow();
        $flow->saveCustom(
            'local-llm',
            'http://127.0.0.1:8080',
            '/v1/chat/completions',
            'env:LOCAL_KEY',
            [
                'my-model' => [
                    'name' => 'My Model',
                    'context_window' => 8192,
                    'max_tokens' => 2048,
                    'input' => ['text'],
                    'tool_calling' => true,
                    'reasoning' => false,
                    'thinking_level_map' => [],
                    'cost' => ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0],
                ],
            ],
            true,
            'reasoning_content',
        );

        $flow->disable('local-llm');

        $settings = $this->parseUserSettings();
        $provider = $settings['ai']['providers']['local-llm'] ?? null;
        $this->assertIsArray($provider);
        $this->assertFalse($provider['enabled']);
        $this->assertSame('http://127.0.0.1:8080', $provider['base_url']);
        $this->assertSame('env:LOCAL_KEY', $provider['api_key']);
        $this->assertArrayHasKey('my-model', $provider['models']);
        $this->assertTrue($provider['compatibility']['supports_developer_role']);
        $this->assertSame('reasoning_content', $provider['compatibility']['thinking_format']);

        $flow->enableCustom('local-llm');
        $settings = $this->parseUserSettings();
        $this->assertTrue($settings['ai']['providers']['local-llm']['enabled']);
        $this->assertSame('http://127.0.0.1:8080', $settings['ai']['providers']['local-llm']['base_url']);
    }

    private function createFlow(?AiConfig $ai = null, bool $project = false): ProvidersSetupFlow
    {
        $pathResolver = new SettingsPathResolver($this->projectDir, $this->homeDir);
        $writer = new SettingsOverrideWriter(
            $pathResolver,
            PropertyAccess::createPropertyAccessor(),
            new Filesystem(),
        );
        $catalog = new AiCatalog($this->catalogPath, $this->homeDir);
        // Seed user catalog copy from fixture so flow reads our 4 providers.
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        copy($this->catalogPath, $this->homeDir.'/.hatfield/ai-catalog.yaml');

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(logDir: $this->tmpDir.'/logs'),
            cwd: $this->projectDir,
            ai: $ai,
        );

        return new ProvidersSetupFlow(
            $catalog,
            $writer,
            $appConfig,
            $project ? SettingsLayerEnum::Project : SettingsLayerEnum::User,
            $this->projectDir,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function parseUserSettings(): array
    {
        $file = $this->homeDir.'/.hatfield/settings.yaml';
        $this->assertFileExists($file);
        $parsed = Yaml::parseFile($file);
        $this->assertIsArray($parsed);

        return $parsed;
    }
}
