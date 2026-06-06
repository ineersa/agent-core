<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi;

use Ineersa\CodingAgent\Auth\CodexAuthRecord;
use Ineersa\CodingAgent\Auth\CodexAuthStorage;
use Ineersa\CodingAgent\Auth\CodexOAuthConfig;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class SymfonyAiProviderFactoryCodexAuthTest extends TestCase
{
    private CodexAuthStorage $authStorage;
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpDir = \sys_get_temp_dir() . '/hatfield-factory-test-' . \bin2hex(\random_bytes(8));
        @\mkdir($this->tmpDir . '/.hatfield', 0755, true);

        $store = new FlockStore($this->tmpDir);
        $lockFactory = new LockFactory($store);
        $this->authStorage = new CodexAuthStorage($this->tmpDir, $lockFactory);
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        $path = $this->tmpDir . '/' . CodexOAuthConfig::AUTH_FILE;
        if (\file_exists($path)) {
            @\unlink($path);
        }
        @\rmdir($this->tmpDir . '/.hatfield');
        @\rmdir($this->tmpDir);
    }

    /**
     * @param array<string, AiProviderConfig> $providers
     */
    private function createFactory(array $providers, ?CodexAuthStorage $codexAuth = null): SymfonyAiProviderFactory
    {
        $aiConfig = new AiConfig(
            defaultModel: 'openai-codex/gpt-5.5',
            providers: $providers,
        );

        $appConfig = new AppConfig(
            tui: TuiConfig::fromArray(['theme' => 'cyberpunk']),
            logging: new LoggingConfig(),
            catalog: new HatfieldModelCatalog($aiConfig),
        );

        return new SymfonyAiProviderFactory(
            appConfig: $appConfig,
            eventDispatcher: $this->createStub(EventDispatcherInterface::class),
            codexAuth: $codexAuth,
        );
    }

    public function testCodexProviderBuiltWithYamlCredentialsWhenPresent(): void
    {
        $provider = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
            apiKey: 'yaml-api-key',
            accountId: 'yaml-account-id',
            models: [
                'gpt-5.5' => new AiModelDefinition(
                    id: 'gpt-5.5',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        $factory = $this->createFactory([$provider->id => $provider]);
        $providers = $factory->createProviders();

        $this->assertArrayHasKey('openai-codex', $providers);
    }

    public function testCodexProviderFallsBackToAuthStorageWhenYamlApiKeyMissing(): void
    {
        $provider = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
            apiKey: '',
            accountId: '',
            models: [
                'gpt-5.5' => new AiModelDefinition(
                    id: 'gpt-5.5',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        // Save credentials into the real auth storage
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'stored-access-token',
            refresh: 'stored-refresh-token',
            expires: \time() + 3600,
            accountId: 'stored-account-id',
        ));

        $factory = $this->createFactory([$provider->id => $provider], $this->authStorage);
        $providers = $factory->createProviders();

        $this->assertArrayHasKey('openai-codex', $providers);
    }

    public function testCodexProviderThrowsWhenBothYamlAndAuthStorageEmpty(): void
    {
        $provider = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
            apiKey: '',
            accountId: '',
            models: [
                'gpt-5.5' => new AiModelDefinition(
                    id: 'gpt-5.5',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        $factory = $this->createFactory([$provider->id => $provider], $this->authStorage);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bin/console auth:codex');

        $factory->createProviders();
    }

    public function testYamlCredentialsOverrideAuthStorage(): void
    {
        $provider = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
            apiKey: 'yaml-override-key',
            accountId: 'yaml-override-account',
            models: [
                'gpt-5.5' => new AiModelDefinition(
                    id: 'gpt-5.5',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        // Save credentials — should NOT be used since YAML provides them
        $this->authStorage->saveCredentials('openai-codex', new CodexAuthRecord(
            access: 'stored-should-not-be-used',
            refresh: 'stored-refresh',
            expires: \time() + 3600,
            accountId: 'stored-account',
        ));

        $factory = $this->createFactory([$provider->id => $provider], $this->authStorage);
        $providers = $factory->createProviders();

        $this->assertArrayHasKey('openai-codex', $providers);
    }
}
