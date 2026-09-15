<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi\Replay\FixtureReplayModelClient;
use Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi\Replay\FixtureReplayResultConverter;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader;
use Ineersa\CodingAgent\Agent\Execution\SessionAwareModelResolver;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Fixture-driven provider-boundary regression coverage. Invocation context is
 * immutable worker input; session metadata remains the resume-time authority
 * for model and reasoning selection.
 */
final class TraceReplayTest extends PerMethodIsolatedKernelTestCase
{
    private string $homeDir;
    private HatfieldSessionStore $sessionMetaStore;

    private \Doctrine\ORM\EntityManagerInterface $entityManager;

    public function testReplayFixtureResolvesSessionModelAndConsumesImmutableInvocationMessages(): void
    {
        $fixture = $this->loadFixture('successful-response.json');
        $sessionId = $this->writeSessionMetadata([
            'model' => $fixture['model'],
            'reasoning' => $fixture['reasoning'],
        ]);
        $messages = $this->fixtureMessages($fixture);
        $modelResolver = $this->createSessionAwareResolver($this->standardAiData());
        $modelClient = new FixtureReplayModelClient($fixture);

        $result = $this->adapter($modelClient, $fixture, $modelResolver)->invoke(new ModelInvocationRequest(
            model: 'fallback/unused',
            input: new ModelInvocationInput(
                runId: $sessionId,
                turnNo: 1,
                stepId: 'turn-1-llm-1',
                messages: $messages,
            ),
        ));

        $this->assertSame($fixture['model'], $modelClient->capturedModel, 'Session metadata model wins at the provider boundary.');
        $this->assertNotNull($result->assistantMessage);
        $this->assertSame($fixture['expected_text'], $result->assistantMessage->asText());
        $this->assertSame($fixture['usage']['input_tokens'], $result->usage['input_tokens']);
        $this->assertSame($fixture['usage']['output_tokens'], $result->usage['output_tokens']);
        $this->assertSame($fixture['usage']['total_tokens'], $result->usage['total_tokens']);

        $session = $this->sessionMetaStore->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame($fixture['model'], $session->model);
        $this->assertSame($fixture['reasoning'], $session->reasoning);
    }

    public function testResumeUsesSessionMetadataOverChangedGlobalDefaults(): void
    {
        $sessionId = $this->writeSessionMetadata([
            'model' => 'llama_cpp/flash',
            'reasoning' => 'off',
        ]);
        $selectionService = $this->createSelectionService([
            'default_model' => 'deepseek/deepseek-v4-pro',
            'default_reasoning' => 'medium',
            'providers' => [
                'deepseek' => [
                    'type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'completions_path' => '/chat/completions',
                    'models' => ['deepseek-v4-pro' => ['id' => 'deepseek-v4-pro', 'name' => 'DeepSeek V4 Pro', 'context_window' => 131072, 'max_tokens' => 131072, 'input' => ['text'], 'reasoning' => true]],
                ],
                'llama_cpp' => [
                    'type' => 'generic', 'enabled' => true, 'base_url' => 'http://127.0.0.1:8052/v1',
                    'models' => ['flash' => ['id' => 'flash', 'name' => 'Flash', 'context_window' => 200000, 'max_tokens' => 65536, 'input' => ['text', 'image'], 'reasoning' => false]],
                ],
            ],
        ]);

        $resolvedModel = $selectionService->resolveInitialModel(null, $sessionId);
        $this->assertNotNull($resolvedModel);
        $this->assertSame('llama_cpp', $resolvedModel->providerId);
        $this->assertSame('flash', $resolvedModel->modelName);
        $this->assertSame('off', $selectionService->resolveInitialReasoning(null, $sessionId));
    }

    public function testModelAndReasoningChangesPersistAcrossResume(): void
    {
        $selectionService = $this->createSelectionService($this->standardAiData());
        $sessionId = $this->writeSessionMetadata([]);

        $session = $this->sessionMetaStore->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertNull($session->model);
        $this->assertNull($session->reasoning);

        $selectionService->changeModel(AiModelReference::parse('deepseek/deepseek-v4-flash'), $sessionId);
        $selectionService->changeReasoning('low', $sessionId);

        $session = $this->sessionMetaStore->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame('deepseek/deepseek-v4-flash', $session->model);
        $this->assertSame('deepseek', $session->modelProvider);
        $this->assertSame('deepseek-v4-flash', $session->modelName);
        $this->assertSame('low', $session->reasoning);
        $this->assertInstanceOf(\DateTimeImmutable::class, $session->updatedAt);
        $this->assertSame('deepseek/deepseek-v4-flash', $selectionService->resolveInitialModel(null, $sessionId)?->toString());
    }

    public function testReplayFixturePreservesThinkingDeltasForImmutableInvocationMessages(): void
    {
        $fixture = $this->loadFixture('successful-response.json');
        $fixture['deltas'] = array_merge([
            ['type' => 'thinking', 'content' => 'The user is asking about recursion.'],
            ['type' => 'thinking_delta', 'content' => 'Let me explain with clear examples.'],
        ], $fixture['deltas']);
        $fixture['expected_text'] = "## Recursion in Programming\n\nRecursion is a technique where a function calls itself to solve smaller instances of the same problem.\n\n### Key Components\n\n1. **Base case** – a condition that stops the recursion\n2. **Recursive case** – the function calls itself with modified arguments";
        $sessionId = $this->writeSessionMetadata([
            'model' => 'deepseek/deepseek-v4-pro',
            'reasoning' => 'high',
        ]);
        $modelResolver = $this->createSessionAwareResolver($this->standardAiData());
        $modelClient = new FixtureReplayModelClient($fixture);

        $result = $this->adapter($modelClient, $fixture, $modelResolver)->invoke(new ModelInvocationRequest(
            model: 'fallback/unused',
            input: new ModelInvocationInput(
                runId: $sessionId,
                turnNo: 1,
                stepId: 'turn-1-llm-1',
                messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'Explain recursion']])],
            ),
        ));

        $this->assertNotNull($result->assistantMessage);
        $this->assertSame($fixture['expected_text'], $result->assistantMessage->asText());
        $this->assertTrue($result->assistantMessage->hasThinking());
        $this->assertSame($fixture['usage']['total_tokens'], $result->usage['total_tokens']);
    }

    protected function afterKernelBoot(): void
    {
        $this->entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->homeDir = $this->isolatedCwd().'/home';
        TestDirectoryIsolation::ensureDirectory($this->homeDir);
        TestDirectoryIsolation::createHatfieldTree($this->homeDir);
        $this->sessionMetaStore = new HatfieldSessionStore(
            appConfig: new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig(), cwd: $this->isolatedCwd()),
            entityManager: $this->entityManager,
            dispatcher: new EventDispatcher(),
        );
    }

    /** @return array<string, mixed> */
    private function loadFixture(string $name): array
    {
        $path = __DIR__.'/../../Fixtures/traces/'.$name;
        $this->assertFileExists($path);
        $fixture = json_decode((string) file_get_contents($path), true);
        $this->assertIsArray($fixture);

        return $fixture;
    }

    /** @param array<string, mixed> $fixture @return list<AgentMessage> */
    private function fixtureMessages(array $fixture): array
    {
        /** @var list<array{role: string, content: string}> $inputMessages */
        $inputMessages = $fixture['input']['messages'];

        return array_map(
            static fn (array $message): AgentMessage => new AgentMessage($message['role'], [['type' => 'text', 'text' => $message['content']]]),
            $inputMessages,
        );
    }

    /** @param array<string, mixed> $fixture */
    private function adapter(FixtureReplayModelClient $modelClient, array $fixture, SessionAwareModelResolver $modelResolver): LlmPlatformAdapter
    {
        $dispatcher = new EventDispatcher();
        $platform = new Platform(
            providers: [new Provider(
                name: 'replay',
                modelClients: [$modelClient],
                resultConverters: [new FixtureReplayResultConverter($fixture)],
                modelCatalog: new FallbackModelCatalog(),
                eventDispatcher: $dispatcher,
            )],
            eventDispatcher: $dispatcher,
        );

        return new LlmPlatformAdapter(
            statusReader: new NullRunOperationalStatusReader(),
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(),
            platform: $platform,
            transformContextHooks: [],
            convertToLlmHooks: [],
            streamObserver: null,
            costCalculator: null,
            logger: new NullLogger(),
            denormalizer: AttributeSerializerValidatorTestFactory::denormalizer(),
            modelResolver: $modelResolver,
        );
    }

    /** @param array<string, mixed> $aiData */
    private function createSessionAwareResolver(array $aiData): SessionAwareModelResolver
    {
        $config = $this->makeAppConfig($aiData);
        $writer = new SettingsOverrideWriter(new SettingsPathResolver($this->isolatedCwd(), $this->homeDir), PropertyAccess::createPropertyAccessor(), new Filesystem());
        $selection = new ModelSelectionService($config, new ModelResolver($config, $this->sessionMetaStore), $writer, $this->sessionMetaStore);
        $catalog = $config->catalog ?? new HatfieldModelCatalog(new AiConfig(defaultModel: '', defaultReasoning: 'medium', providers: []));

        return new SessionAwareModelResolver($selection, $catalog, $this->sessionMetaStore);
    }

    /** @param array<string, mixed> $aiData */
    private function createSelectionService(array $aiData): ModelSelectionService
    {
        $config = $this->makeAppConfig($aiData);
        $writer = new SettingsOverrideWriter(new SettingsPathResolver($this->isolatedCwd(), $this->homeDir), PropertyAccess::createPropertyAccessor(), new Filesystem());

        return new ModelSelectionService($config, new ModelResolver($config, $this->sessionMetaStore), $writer, $this->sessionMetaStore);
    }

    /** @param array<string, mixed> $aiData */
    private function makeAppConfig(array $aiData): AppConfig
    {
        $raw = ['tui' => ['theme' => 'cyberpunk'], 'ai' => $aiData];
        $ai = AiConfig::optionalFromArray($raw);

        return new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            ai: $ai,
            raw: $raw,
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: $this->isolatedCwd(),
        );
    }

    /** @param array<string, string> $metadata */
    private function writeSessionMetadata(array $metadata): string
    {
        $session = new HatfieldSession();
        $session->cwd = $this->isolatedCwd();
        $this->entityManager->persist($session);
        $this->entityManager->flush();
        $session->model = $metadata['model'] ?? null;
        $session->reasoning = $metadata['reasoning'] ?? null;
        $this->entityManager->flush();

        return (string) $session->id;
    }

    /** @return array<string, mixed> */
    private function standardAiData(): array
    {
        return [
            'default_model' => 'deepseek/deepseek-v4-pro',
            'default_reasoning' => 'medium',
            'providers' => [
                'deepseek' => [
                    'type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'completions_path' => '/chat/completions',
                    'models' => [
                        'deepseek-v4-pro' => ['id' => 'deepseek-v4-pro', 'name' => 'DeepSeek V4 Pro', 'context_window' => 131072, 'max_tokens' => 131072, 'input' => ['text'], 'reasoning' => true, 'tool_calling' => true, 'thinking_level_map' => ['minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'max']],
                        'deepseek-v4-flash' => ['id' => 'deepseek-v4-flash', 'name' => 'DeepSeek V4 Flash', 'context_window' => 131072, 'max_tokens' => 131072, 'input' => ['text'], 'reasoning' => false],
                    ],
                ],
                'llama_cpp' => [
                    'type' => 'generic', 'enabled' => true, 'base_url' => 'http://127.0.0.1:8052/v1',
                    'models' => ['flash' => ['id' => 'flash', 'name' => 'Flash', 'context_window' => 200000, 'max_tokens' => 65536, 'input' => ['text', 'image'], 'reasoning' => false]],
                ],
            ],
        ];
    }
}
