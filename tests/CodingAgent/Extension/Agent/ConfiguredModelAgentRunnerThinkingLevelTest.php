<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Agent;

use Ineersa\AgentCore\Contract\Model\ModelResolverInterface;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderCompatibilityRequestShaper;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderRequestPreparer;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ReasoningOptionsFeatureShaper;
use Ineersa\CodingAgent\Agent\Execution\SessionAwareModelResolver;
use Ineersa\CodingAgent\Config\Ai\AiCompatibility;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiHttpConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Extension\Agent\ConfiguredModelAgentRunner;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ConfiguredSymfonyAiPlatformFactory;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderFactory;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Agent\AgentCallRequestDTO;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolverInterface;
use Symfony\AI\Platform\Platform;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Thesis: explicit AgentCallRequestDTO thinkingLevel=off adds llama.cpp disable options
 * only when catalog thinking_format=llama_cpp; null/default and session off do not.
 */
final class ConfiguredModelAgentRunnerThinkingLevelTest extends IsolatedKernelTestCase
{
    private string $tempDir;

    private string $homeDir;

    private \Doctrine\ORM\EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('hatfield-ext-agent-thinking', 0o750);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir.'/.hatfield', 0777, true);
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testExplicitOffWithLlamaCppThinkingFormatEmitsDisableFlagAndKeepsBudget(): void
    {
        $seen = [];
        $runner = $this->createRunner(
            $this->captureTransport($seen),
            thinkingFormat: 'llama_cpp',
            defaultReasoning: 'medium',
        );

        $runner->run(new AgentCallRequestDTO(
            model: 'llama_cpp/flash',
            sessionId: $this->writeSession(['model' => 'llama_cpp/flash', 'reasoning' => 'medium']),
            instructions: 'sys',
            input: 'user',
            maxDurationSeconds: 300,
            thinkingLevel: 'off',
        ));

        $this->assertCount(1, $seen);
        $this->assertSame(300.0, (float) $seen[0]['max_duration']);
        $this->assertSame(300.0, (float) $seen[0]['timeout']);
        $body = $this->decodeBody($seen[0]);
        $this->assertSame(['enable_thinking' => false], $body['chat_template_kwargs'] ?? null);
        $this->assertArrayNotHasKey('max_duration', $body);
        $this->assertTrue($body['stream'] ?? false);
    }

    public function testNullThinkingLevelDoesNotEmitDisableFlagEvenWhenSessionReasoningIsOff(): void
    {
        $seen = [];
        $runner = $this->createRunner(
            $this->captureTransport($seen),
            thinkingFormat: 'llama_cpp',
            defaultReasoning: 'off',
        );

        $runner->run(new AgentCallRequestDTO(
            model: 'llama_cpp/flash',
            sessionId: $this->writeSession(['model' => 'llama_cpp/flash', 'reasoning' => 'off']),
            instructions: 'sys',
            input: 'user',
            maxDurationSeconds: 300,
        ));

        $this->assertCount(1, $seen);
        $body = $this->decodeBody($seen[0]);
        $this->assertArrayNotHasKey('chat_template_kwargs', $body);
        $this->assertArrayNotHasKey('thinking', $body);
        $this->assertSame(300.0, (float) $seen[0]['max_duration']);
        $this->assertSame(300.0, (float) $seen[0]['timeout']);
    }

    public function testExplicitOffWithoutCatalogThinkingFormatDoesNotInventDisableFlag(): void
    {
        $seen = [];
        $runner = $this->createRunner(
            $this->captureTransport($seen),
            thinkingFormat: null,
            defaultReasoning: 'medium',
        );

        $runner->run(new AgentCallRequestDTO(
            model: 'llama_cpp/flash',
            sessionId: $this->writeSession(['model' => 'llama_cpp/flash', 'reasoning' => 'medium']),
            instructions: 'sys',
            input: 'user',
            maxDurationSeconds: 300,
            thinkingLevel: 'off',
        ));

        $this->assertCount(1, $seen);
        $body = $this->decodeBody($seen[0]);
        $this->assertArrayNotHasKey('chat_template_kwargs', $body);
        $this->assertSame(300.0, (float) $seen[0]['max_duration']);
        $this->assertSame(300.0, (float) $seen[0]['timeout']);
    }

    /**
     * @param list<array<string, mixed>> $seen
     */
    private function captureTransport(array &$seen): MockHttpClient
    {
        return new MockHttpClient(static function (string $method, string $url, array $options) use (&$seen): MockResponse {
            $seen[] = $options;

            return new MockResponse(self::streamPayload('ok'));
        });
    }

    private function createRunner(
        HttpClientInterface $transport,
        ?string $thinkingFormat,
        string $defaultReasoning,
    ): ConfiguredModelAgentRunner {
        $providerCompat = null === $thinkingFormat ? null : new AiCompatibility(thinkingFormat: $thinkingFormat);
        $ai = new AiConfig(
            defaultModel: 'llama_cpp/flash',
            defaultReasoning: $defaultReasoning,
            http: new AiHttpConfig(timeout: 30, maxDuration: 120),
            providers: [
                'llama_cpp' => new AiProviderConfig(
                    id: 'llama_cpp',
                    type: 'generic',
                    enabled: true,
                    baseUrl: 'https://example.test/v1',
                    apiKey: 'test-key',
                    compatibility: $providerCompat,
                    models: [
                        'flash' => new AiModelDefinition(
                            id: 'flash',
                            name: 'flash',
                            toolCalling: true,
                            reasoning: true,
                            contextWindow: 160000,
                        ),
                    ],
                ),
            ],
        );
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            ai: $ai,
            catalog: new HatfieldModelCatalog($ai),
            cwd: $this->tempDir.'/project',
        );

        $dispatcher = $this->createStub(EventDispatcherInterface::class);
        $providerFactory = new SymfonyAiProviderFactory(
            $appConfig,
            $dispatcher,
            [],
            new NullLogger(),
            $transport,
        );
        $providers = $providerFactory->createProviders();
        $this->assertArrayHasKey('llama_cpp', $providers);
        $platformFactory = new ConfiguredSymfonyAiPlatformFactory($providerFactory, $dispatcher);

        return new ConfiguredModelAgentRunner(
            new Platform(array_values($providers)),
            $platformFactory,
            new HatfieldModelCatalog($ai),
            new NullLogger(),
            $this->createStub(ToolCallArgumentResolverInterface::class),
            $this->createSessionAwareResolver($appConfig),
            new ProviderRequestPreparer(
                hooks: [],
                compatShaper: new ProviderCompatibilityRequestShaper([
                    new ReasoningOptionsFeatureShaper(),
                ]),
            ),
        );
    }

    private function createSessionAwareResolver(AppConfig $appConfig): ModelResolverInterface
    {
        $sessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->entityManager,
            dispatcher: new EventDispatcher(),
        );
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new SettingsOverrideWriter($pathResolver, PropertyAccess::createPropertyAccessor(), new Filesystem());
        $selectionService = new ModelSelectionService(
            $appConfig,
            new ModelResolver($appConfig, $sessionStore, new NullLogger()),
            $homeWriter,
            $sessionStore,
        );
        $catalog = $appConfig->catalog ?? new HatfieldModelCatalog(new AiConfig(defaultModel: '', defaultReasoning: 'medium', providers: []));

        return new SessionAwareModelResolver($selectionService, $catalog, $sessionStore);
    }

    /**
     * @param array{model?: string, reasoning?: string} $meta
     */
    private function writeSession(array $meta): string
    {
        $entity = new HatfieldSession();
        $entity->cwd = $this->tempDir.'/project';
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        if (isset($meta['model'])) {
            $entity->model = $meta['model'];
        }
        if (isset($meta['reasoning'])) {
            $entity->reasoning = $meta['reasoning'];
        }
        $this->entityManager->flush();

        return (string) $entity->id;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function decodeBody(array $options): array
    {
        $body = $options['body'] ?? null;
        if (\is_string($body)) {
            $decoded = json_decode($body, true);
            $this->assertIsArray($decoded);

            return $decoded;
        }
        if (\is_array($options['json'] ?? null)) {
            return $options['json'];
        }

        $this->fail('expected JSON request body');
    }

    private static function streamPayload(string $content): string
    {
        $chunk = json_encode([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion.chunk',
            'choices' => [[
                'index' => 0,
                'delta' => ['role' => 'assistant', 'content' => $content],
                'finish_reason' => null,
            ]],
        ], \JSON_THROW_ON_ERROR);
        $done = json_encode([
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion.chunk',
            'choices' => [[
                'index' => 0,
                'delta' => new \stdClass(),
                'finish_reason' => 'stop',
            ]],
        ], \JSON_THROW_ON_ERROR);

        return "data: {$chunk}\n\ndata: {$done}\n\ndata: [DONE]\n\n";
    }
}
