<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Providers;

use Ineersa\CodingAgent\CLI\Providers\ProvidersSetupCommand;
use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Yaml\Yaml;

/**
 * Thesis: providers:setup writes sparse known-provider overrides (never models:),
 * OAuth prints auth next-step without executing it, custom providers write a full
 * definition, and --project targets the project settings layer.
 */
#[CoversClass(ProvidersSetupCommand::class)]
final class ProvidersSetupCommandTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private string $projectDir;
    private string $catalogPath;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('providers_setup');
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
        label: 'Grok CLI (xAI)'
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
        $tester = new CommandTester($this->createCommand());
        $tester->setInputs([
            'zai',          // provider
            'env',          // where is key
            'ZAI_API_KEY',  // env name
            'no',           // add another?
            'yes',          // set default model?
            'zai/glm-5.3',  // default model
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $settings = $this->parseUserSettings();
        $this->assertSame([
            'enabled' => true,
            'api_key' => 'env:ZAI_API_KEY',
        ], $settings['ai']['providers']['zai'] ?? null);
        $this->assertArrayNotHasKey('models', $settings['ai']['providers']['zai']);
        $this->assertArrayNotHasKey('base_url', $settings['ai']['providers']['zai']);
        $this->assertSame('zai/glm-5.3', $settings['ai']['default_model'] ?? null);
        $this->assertStringContainsString('Provider "zai" enabled', $tester->getDisplay());
    }

    #[Test]
    public function presetRawKeyWritesSparseApiKey(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->setInputs([
            'deepseek',
            'raw',
            'sk-test-raw-key',
            'no',
            'no', // default model
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $settings = $this->parseUserSettings();
        $provider = $settings['ai']['providers']['deepseek'] ?? null;
        $this->assertIsArray($provider);
        $this->assertTrue($provider['enabled']);
        $this->assertSame('sk-test-raw-key', $provider['api_key']);
        $this->assertArrayNotHasKey('models', $provider);
        $this->assertArrayNotHasKey('default_model', $settings['ai'] ?? []);
    }

    #[Test]
    public function oauthFlowWritesEnabledOnlyAndPrintsAuthHintWithoutExecuting(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->setInputs([
            'grok-cli',
            'no', // add another
            'no', // default model
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $display = $tester->getDisplay();
        $this->assertStringContainsString('`hatfield auth:grok`', $display);
        $this->assertStringNotContainsString('Authenticate with xAI', $display);

        $settings = $this->parseUserSettings();
        $provider = $settings['ai']['providers']['grok-cli'] ?? null;
        $this->assertSame(['enabled' => true], $provider);
        $this->assertArrayNotHasKey('models', $provider);
        $this->assertArrayNotHasKey('api_key', $provider);
    }

    #[Test]
    public function customHappyPathWritesFullDefinition(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->setInputs([
            'custom',
            'local-llm',
            'http://127.0.0.1:8080',
            '/v1/chat/completions',
            'no',           // no api key
            'my-model',     // model id
            'My Model',     // display name
            '8192',         // context
            '2048',         // max tokens
            'text',         // modalities
            'no',           // reasoning
            'no',           // another model
            'no',           // supports_developer_role
            '',             // thinking_format empty
            'no',           // add another provider
            'yes',          // default model
            'local-llm/my-model',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $settings = $this->parseUserSettings();
        $provider = $settings['ai']['providers']['local-llm'] ?? null;
        $this->assertIsArray($provider);
        $this->assertTrue($provider['enabled']);
        $this->assertSame('http://127.0.0.1:8080', $provider['base_url']);
        $this->assertSame('/v1/chat/completions', $provider['completions_path']);
        $this->assertSame('openai-completions', $provider['api']);
        $this->assertArrayHasKey('models', $provider);
        $this->assertArrayHasKey('my-model', $provider['models']);
        $this->assertSame(['text'], $provider['models']['my-model']['input']);
        $this->assertFalse($provider['models']['my-model']['reasoning']);
        $this->assertSame(0, $provider['models']['my-model']['cost']['input']);
        $this->assertFalse($provider['compatibility']['supports_developer_role']);
        $this->assertSame('local-llm/my-model', $settings['ai']['default_model'] ?? null);
    }

    #[Test]
    public function projectFlagWritesProjectLayer(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->setInputs([
            'openai-codex',
            'no',
            'no',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute(['--project' => true]), $tester->getDisplay());

        $this->assertFileDoesNotExist($this->homeDir.'/.hatfield/settings.yaml');
        $projectFile = $this->projectDir.'/.hatfield/settings.yaml';
        $this->assertFileExists($projectFile);
        $settings = Yaml::parseFile($projectFile);
        $this->assertIsArray($settings);
        $this->assertSame(['enabled' => true], $settings['ai']['providers']['openai-codex'] ?? null);
        $this->assertStringContainsString('`hatfield auth:codex`', $tester->getDisplay());
    }

    #[Test]
    public function knownProviderNeverReceivesModelsKeyEvenAcrossMultiplePresets(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->setInputs([
            'zai',
            'env',
            'ZAI_API_KEY',
            'yes', // another
            'grok-cli',
            'no',
            'no',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $settings = $this->parseUserSettings();
        foreach (['zai', 'grok-cli'] as $id) {
            $this->assertArrayHasKey($id, $settings['ai']['providers']);
            $this->assertArrayNotHasKey('models', $settings['ai']['providers'][$id]);
        }
    }

    #[Test]
    public function disableFlowWritesEnabledFalseWithoutModels(): void
    {
        $tester = new CommandTester($this->createCommand(
            ai: new AiConfig(
                providers: [
                    'grok-cli' => new AiProviderConfig(id: 'grok-cli', type: 'grok', enabled: true),
                ],
            ),
        ));
        $tester->setInputs([
            'grok-cli',
            'disable',
            'no', // add another
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Provider "grok-cli" disabled', $display);
        $this->assertStringNotContainsString('`hatfield auth:grok`', $display);

        $settings = $this->parseUserSettings();
        $provider = $settings['ai']['providers']['grok-cli'] ?? null;
        $this->assertSame(['enabled' => false], $provider);
        $this->assertArrayNotHasKey('models', $provider);
    }

    #[Test]
    public function enableThenDisableSameRunDropsAuthHintAndDefaultChoices(): void
    {
        $tester = new CommandTester($this->createCommand());
        $tester->setInputs([
            'grok-cli',      // enable oauth
            'yes',           // add another
            'grok-cli',      // pick again (now [enabled] this run)
            'disable',
            'yes',           // add another → picker shows [disabled]
            'done',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $display = $tester->getDisplay();
        $this->assertStringContainsString('Provider "grok-cli" enabled', $display);
        $this->assertStringContainsString('Provider "grok-cli" disabled', $display);
        $this->assertStringContainsString('[disabled]', $display);
        $this->assertStringNotContainsString('`hatfield auth:grok`', $display);
        $this->assertStringNotContainsString('Set default model?', $display);

        $settings = $this->parseUserSettings();
        $this->assertSame(['enabled' => false], $settings['ai']['providers']['grok-cli'] ?? null);
    }

    #[Test]
    public function disablingDefaultModelProviderWarnsWithoutRewriting(): void
    {
        $tester = new CommandTester($this->createCommand(
            ai: new AiConfig(
                defaultModel: 'zai/glm-5.3',
                providers: [
                    'zai' => new AiProviderConfig(id: 'zai', enabled: true),
                ],
            ),
        ));
        $tester->setInputs([
            'zai',
            'disable',
            'no',
        ]);

        $this->assertSame(Command::SUCCESS, $tester->execute([]), $tester->getDisplay());

        $display = $tester->getDisplay();
        $this->assertStringContainsString('ai.default_model "zai/glm-5.3"', $display);
        $this->assertStringContainsString('now unavailable', $display);
        $this->assertStringContainsString('Re-run setup', $display);

        $settings = $this->parseUserSettings();
        $this->assertSame(['enabled' => false], $settings['ai']['providers']['zai'] ?? null);
        $this->assertArrayNotHasKey('default_model', $settings['ai'] ?? []);
    }

    private function createCommand(?AiConfig $ai = null): ProvidersSetupCommand
    {
        $pathResolver = new SettingsPathResolver($this->projectDir, $this->homeDir);
        $writer = new SettingsOverrideWriter(
            $pathResolver,
            PropertyAccess::createPropertyAccessor(),
            new Filesystem(),
        );
        $catalog = new AiCatalog($this->catalogPath, $this->homeDir);
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(logDir: $this->tmpDir.'/logs'),
            cwd: $this->projectDir,
            ai: $ai,
        );

        return new ProvidersSetupCommand($catalog, $writer, $appConfig);
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
