<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class SymfonyAiProviderFactoryTest extends TestCase
{
    public function testGenericTypeBuildsProvider(): void
    {
        $appConfig = $this->appConfig();
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $factory = new SymfonyAiProviderFactory($appConfig, $eventDispatcher);

        $providers = $factory->createProviders();

        $this->assertArrayHasKey('deepseek', $providers);
        $this->assertNotNull($providers['deepseek']);

        // Verify the generic path still produces CompletionsModel
        $catalog = $providers['deepseek']->getModelCatalog();
        $model = $catalog->getModel('deepseek/deepseek-v4-pro');
        $this->assertInstanceOf(CompletionsModel::class, $model);
    }

    public function testCodexTypeThrowsWithoutAuthStorage(): void
    {
        $providerConfig = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires stored OAuth credentials');

        $factory = $this->createFactory(['openai-codex' => $providerConfig]);
        $factory->createProviders();
    }

    public function testCodexTypeThrowsRegardlessOfYamlCredentials(): void
    {
        $providerConfig = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
            apiKey: 'some-access-token',
            accountId: 'chat-123456',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires stored OAuth credentials');

        $factory = $this->createFactory(['openai-codex' => $providerConfig]);
        $factory->createProviders();
    }

    public function testDisabledCodexProviderIsSkipped(): void
    {
        $providerConfig = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: false,
            baseUrl: 'https://chatgpt.com/backend-api',
        );

        $factory = $this->createFactory(['openai-codex' => $providerConfig]);
        $providers = $factory->createProviders();

        $this->assertArrayNotHasKey('openai-codex', $providers);
    }

    /**
     * @param array<string, AiProviderConfig> $providers
     */
    private function createFactory(array $providers): SymfonyAiProviderFactory
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
            $appConfig,
            $this->createStub(EventDispatcherInterface::class),
        );
    }

    private function appConfig(): AppConfig
    {
        $deepseekConfig = new AiProviderConfig(
            id: 'deepseek',
            type: 'generic',
            enabled: true,
            baseUrl: 'https://api.deepseek.com',
            apiKey: 'dummy-key',
            models: [
                'deepseek-v4-pro' => new AiModelDefinition(
                    id: 'deepseek-v4-pro',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        $aiConfig = new AiConfig(
            defaultModel: 'deepseek/deepseek-v4-pro',
            providers: ['deepseek' => $deepseekConfig],
        );

        return new AppConfig(
            tui: TuiConfig::fromArray(['theme' => 'cyberpunk']),
            logging: new LoggingConfig(),
            catalog: new HatfieldModelCatalog($aiConfig),
        );
    }
}
