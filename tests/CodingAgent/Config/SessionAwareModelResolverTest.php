<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\LlmStepResultHandler;
use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ModelResolverRoutingSubscriber;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\Fake\FakeStreamResultConverter;
use Ineersa\AgentCore\Tests\Support\Fake\FakeSymfonyModelClient;
use Ineersa\AgentCore\Tests\Support\Fake\FakeTokenUsage;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionAwareModelResolver;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class SessionAwareModelResolverTest extends IsolatedKernelTestCase
{
    private string $tempDir;
    private string $homeDir;
    private \Doctrine\ORM\EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine.orm.default_entity_manager');

        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('hatfield-resolver', 0o750);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir, 0777, true);
        mkdir($this->homeDir.'/.hatfield', 0777, true);
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tempDir);
        parent::tearDown(); // clears EM via IsolatedKernelTestCase
    }

    public function testSessionMetadataUsedWhenNoExplicitModel(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $sessionId = $this->writeSessionMetadata('sess-1', ['model' => 'llama_cpp/flash']);

        // Empty defaultModel => no explicit override => session metadata model wins.
        $result = $resolver->resolve(
            '',
            new MessageBag(),
            new ModelInvocationInput(runId: $sessionId),
            new ModelResolutionOptions(),
        );

        $this->assertSame('llama_cpp/flash', $result->model);
        $this->assertSame('llama_cpp', $result->providerId);
        $this->assertSame('medium', $result->reasoning);
        $this->assertArrayHasKey('provider_cache_key', $result->options);
        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($result->options['provider_cache_key']));
    }

    public function testExplicitModelWinsOverSessionMetadata(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $sessionId = $this->writeSessionMetadata('sess-explicit', ['model' => 'llama_cpp/flash']);

        // Non-empty defaultModel is an explicit override and wins over
        // session metadata.  This is the compaction/summarization path where
        // the caller already resolved a specific model string.
        $result = $resolver->resolve(
            'deepseek/deepseek-v4-pro',
            new MessageBag(),
            new ModelInvocationInput(runId: $sessionId),
            new ModelResolutionOptions(),
        );

        $this->assertSame('deepseek/deepseek-v4-pro', $result->model);
        $this->assertSame('deepseek', $result->providerId);
    }

    public function testResolveReturnsDefaultModelWhenNoSessionMetadata(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $result = $resolver->resolve(
            'deepseek/deepseek-v4-pro',
            new MessageBag(),
            new ModelInvocationInput(),
            new ModelResolutionOptions(),
        );

        $this->assertSame('deepseek/deepseek-v4-pro', $result->model);
        $this->assertSame('deepseek', $result->providerId);
        $this->assertSame('medium', $result->reasoning);
    }

    public function testResolveReturnsFirstAvailableWhenNoSessionOrDefault(): void
    {
        $data = $this->standardAiData();
        unset($data['default_model'], $data['default_reasoning']);
        $resolver = $this->createResolver($data);

        $result = $resolver->resolve(
            'fallback-model',
            new MessageBag(),
            new ModelInvocationInput(),
            new ModelResolutionOptions(),
        );

        $this->assertNotEmpty($result->model);
        $this->assertNotEmpty($result->providerId);
    }

    public function testResolveThrowsWhenNoModelsConfigured(): void
    {
        $resolver = $this->createResolver([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No AI model is configured');

        $resolver->resolve(
            '',
            new MessageBag(),
            new ModelInvocationInput(),
            new ModelResolutionOptions(),
        );
    }

    public function testNameMetadataDoesNotAffectModelResolution(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $sessionId = $this->writeSessionMetadata('sess-name', [
            'model' => 'llama_cpp/flash',
            'name' => 'My Session',
        ]);

        // Empty defaultModel => no explicit override => session metadata model wins.
        $result = $resolver->resolve(
            '',
            new MessageBag(),
            new ModelInvocationInput(runId: $sessionId),
            new ModelResolutionOptions(),
        );

        // name metadata must not affect model or reasoning resolution
        $this->assertSame('llama_cpp/flash', $result->model);
        $this->assertSame('llama_cpp', $result->providerId);
        $this->assertSame('medium', $result->reasoning);
    }

    public function testResolveThrowsWhenNoModelsConfiguredAndLegacyDefaultProvided(): void
    {
        $resolver = $this->createResolver([]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No AI model is configured');

        $resolver->resolve(
            'any-model',
            new MessageBag(),
            new ModelInvocationInput(),
            new ModelResolutionOptions(),
        );
    }

    public function testReasoningFromSessionMetadataWhenNoExplicitOverride(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $sessionId = $this->writeSessionMetadata('sess-2', ['model' => 'deepseek/deepseek-v4-pro', 'reasoning' => 'high']);

        // Empty thinking_level in options + empty defaultModel => session reasoning wins.
        $result = $resolver->resolve(
            '',
            new MessageBag(),
            new ModelInvocationInput(runId: $sessionId),
            new ModelResolutionOptions(),
        );

        $this->assertSame('high', $result->reasoning);
    }

    public function testThinkingLevelOptionOverridesSessionReasoning(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $sessionId = $this->writeSessionMetadata('sess-3', ['model' => 'deepseek/deepseek-v4-pro', 'reasoning' => 'high']);

        // thinking_level in ModelResolutionOptions overrides session reasoning.
        $result = $resolver->resolve(
            '',
            new MessageBag(),
            new ModelInvocationInput(runId: $sessionId),
            new ModelResolutionOptions(['thinking_level' => 'low']),
        );

        $this->assertSame('low', $result->reasoning);
    }

    public function testEmptyThinkingLevelDoesNotOverrideSessionReasoning(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $sessionId = $this->writeSessionMetadata('sess-4', ['model' => 'deepseek/deepseek-v4-pro', 'reasoning' => 'high']);

        // Empty string thinking_level => no override => session reasoning wins.
        $result = $resolver->resolve(
            '',
            new MessageBag(),
            new ModelInvocationInput(runId: $sessionId),
            new ModelResolutionOptions(['thinking_level' => '']),
        );

        $this->assertSame('high', $result->reasoning);
    }

    public function testUuidV7ChildRunWithoutSessionRowResolvesWithoutProviderCacheKey(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $childRunId = UuidV7::v7()->toRfc4122();

        $result = $resolver->resolve(
            '',
            new MessageBag(),
            new ModelInvocationInput(runId: $childRunId),
            new ModelResolutionOptions(),
        );

        $this->assertSame('deepseek/deepseek-v4-pro', $result->model);
        $this->assertSame([], $result->options);
    }

    public function testEphemeralHexRunWithoutSessionRowResolvesWithoutProviderCacheKey(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $result = $resolver->resolve(
            '',
            new MessageBag(),
            new ModelInvocationInput(runId: 'db1f3c6bdccc'),
            new ModelResolutionOptions(),
        );

        $this->assertSame([], $result->options);
    }

    public function testMissingNumericSessionMetadataThrows(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Session "42" has no metadata for model resolution.');

        $resolver->resolve(
            '',
            new MessageBag(),
            new ModelInvocationInput(runId: '42'),
            new ModelResolutionOptions(),
        );
    }

    public function testNumericSessionWithNullProviderCacheKeyInDatabaseThrowsExplicitRuntimeException(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $sessionId = $this->writeSessionMetadata('sess-null-key', ['model' => 'llama_cpp/flash']);

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE hatfield_session SET provider_cache_key = NULL WHERE id = ?',
            [(int) $sessionId],
        );
        $this->entityManager->clear();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(\sprintf('Session "%s" is missing a provider_cache_key.', $sessionId));

        $resolver->resolve(
            '',
            new MessageBag(),
            new ModelInvocationInput(runId: $sessionId),
            new ModelResolutionOptions(),
        );
    }

    public function testZaiOffReasoningProducesDisabledThinkingOptions(): void
    {
        $aiData = $this->standardAiData();
        $aiData['providers']['zai'] = [
            'type' => 'generic',
            'enabled' => true,
            'base_url' => 'https://api.z.ai/api/coding/paas/v4',
            'compatibility' => [
                'supports_developer_role' => false,
                'supports_reasoning_effort' => false,
                'thinking_format' => 'zai',
            ],
            'models' => [
                'glm-5.1' => [
                    'id' => 'glm-5.1',
                    'name' => 'GLM 5.1',
                    'context_window' => 200000,
                    'max_tokens' => 131072,
                    'input' => ['text'],
                    'tool_calling' => true,
                    'reasoning' => true,
                    'thinking_level_map' => ['medium' => 'enabled'],
                    'compatibility' => ['zai_tool_stream' => true],
                ],
            ],
        ];
        $aiData['default_model'] = 'zai/glm-5.1';
        $aiData['default_reasoning'] = 'off';

        $resolver = $this->createResolver($aiData);

        $result = $resolver->resolve(
            '',
            new MessageBag(),
            new ModelInvocationInput(),
            new ModelResolutionOptions(),
        );

        $this->assertSame('off', $result->reasoning);
        $this->assertSame(['thinking' => ['type' => 'disabled']], $result->reasoningOptions);
        $this->assertContains('reasoning', $result->compatFeatures);
    }

    /**
     * Session-41 regression: the resumed session DB row says Sol/high while the
     * historical run_started event says Grok/minimal.  Ordinary execution must
     * use the current session selection — the actual provider request and the
     * resulting llm_step_completed event carry Sol/high.
     */
    public function testSessionDbSelectionDrivesProviderRequestAndCompletionEvent(): void
    {
        $resolver = $this->createResolver($this->codexAiData());
        $sessionId = $this->writeSessionMetadata('sess-41', [
            'model' => 'openai-codex/gpt-5.6-sol',
            'reasoning' => 'high',
        ]);

        // Current session selection wins at the provider boundary.
        $resolved = $resolver->resolve(
            '',
            new MessageBag(),
            new ModelInvocationInput(runId: $sessionId),
            new ModelResolutionOptions(),
        );
        $this->assertSame('openai-codex/gpt-5.6-sol', $resolved->model);
        $this->assertSame('high', $resolved->reasoning);

        // Historical run_started says Grok — replay projection only, no
        // execution override.
        $state = (new RunStateReducer())->replay(RunState::queued($sessionId), [
            new RunEvent(
                runId: $sessionId,
                seq: 1,
                turnNo: 0,
                type: RunEventTypeEnum::RunStarted->value,
                payload: [
                    'step_id' => 'start',
                    'payload' => [
                        'messages' => [],
                        'metadata' => ['model' => 'grok-cli/grok-composer-2.5-fast'],
                    ],
                ],
            ),
        ]);
        $this->assertSame('grok-cli/grok-composer-2.5-fast', $state->model, 'Historical model stays a replay projection.');

        // Scheduling carries no model snapshot.
        $advanceHandler = new AdvanceRunHandler(
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: new InMemoryCommandStore(),
                commandRouter: new CommandRouter([]),
            ),
            eventFactory: new EventFactory(),
        );
        $advanceResult = $advanceHandler->handle(
            new AdvanceRun($sessionId, 0, 'adv-41', 1, 'ik-adv-41'),
            $state,
        );

        $effect = null;
        foreach ($advanceResult->effects as $candidate) {
            if ($candidate instanceof ExecuteLlmStep) {
                $effect = $candidate;
            }
        }
        $this->assertInstanceOf(ExecuteLlmStep::class, $effect);
        $this->assertFalse(property_exists($effect, 'model'), 'Scheduling must not snapshot a model onto ExecuteLlmStep.');

        // Provider boundary: the actual provider request is Sol/high.
        $client = new FakeSymfonyModelClient(new FakeTokenUsage(promptTokens: 5, completionTokens: 3, totalTokens: 8));
        $adapter = $this->createCodexAdapter($resolver, $client);
        $response = $adapter->invoke(new ModelInvocationRequest(
            model: '',
            input: new ModelInvocationInput(runId: $sessionId, turnNo: $advanceResult->nextState->turnNo, stepId: $effect->stepId()),
        ));

        $this->assertSame('openai-codex/gpt-5.6-sol', $client->capturedModel);
        $this->assertSame('high', $client->capturedOptions['_hatfield_reasoning'] ?? null);
        $this->assertSame('openai-codex/gpt-5.6-sol', $response->model);
        $this->assertSame('high', $response->reasoning);

        // Completion event carries the actual resolved identity.
        $resultHandler = new LlmStepResultHandler(
            toolBatchCollector: new ToolBatchCollector(),
            commandMailboxPolicy: new CommandMailboxPolicy(
                commandStore: new InMemoryCommandStore(),
                commandRouter: new CommandRouter([]),
            ),
            eventFactory: new EventFactory(),
            toolCallExtractor: new ToolCallExtractor(),
            messageNormalizer: new AgentMessageNormalizer(),
            stepDispatcher: new StepDispatcher(new TestMessageBus()),
            normalizer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );
        $stepResult = new LlmStepResult(
            runId: $sessionId,
            turnNo: $advanceResult->nextState->turnNo,
            stepId: $effect->stepId(),
            attempt: 1,
            idempotencyKey: 'ik-llm-41',
            assistantMessage: $response->assistantMessage,
            usage: $response->usage,
            stopReason: $response->stopReason,
            toolsRef: $effect->toolsRef,
            model: $response->model,
            reasoning: $response->reasoning,
            modelNotifications: $response->modelNotifications,
            availableTools: $response->availableTools,
            availableToolsSchemaTokensEstimate: $response->availableToolsSchemaTokensEstimate,
        );
        $result = $resultHandler->handle($stepResult, $advanceResult->nextState);

        $completed = null;
        foreach ($result->events as $event) {
            if (RunEventTypeEnum::LlmStepCompleted->value === $event->type) {
                $completed = $event;
            }
        }
        $this->assertNotNull($completed, 'llm_step_completed must be emitted.');
        $this->assertSame('openai-codex/gpt-5.6-sol', $completed->payload['model'] ?? null);
        $this->assertSame('high', $completed->payload['reasoning'] ?? null);
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    private function createCodexAdapter(SessionAwareModelResolver $resolver, FakeSymfonyModelClient $client): LlmPlatformAdapter
    {
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new ModelResolverRoutingSubscriber($resolver));

        $platform = new Platform(
            providers: [new Provider(
                name: 'fake',
                modelClients: [$client],
                resultConverters: [new FakeStreamResultConverter(static fn (): iterable => [new TextDelta('ok')])],
                modelCatalog: new \Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog(),
                eventDispatcher: $eventDispatcher,
            )],
            eventDispatcher: $eventDispatcher,
        );

        return new LlmPlatformAdapter(
            runStore: new InMemoryRunStore(),
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(),
            platform: $platform,
            transformContextHooks: [],
            convertToLlmHooks: [],
            streamObserver: null,
            costCalculator: null,
            modelResolver: $resolver,
            logger: new TestLogger(),
            denormalizer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );
    }

    private function codexAiData(): array
    {
        return [
            'default_model' => 'openai-codex/gpt-5.6-sol',
            'default_reasoning' => 'medium',
            'providers' => [
                'openai-codex' => [
                    'type' => 'codex',
                    'enabled' => true,
                    'base_url' => 'https://chatgpt.com/backend-api',
                    'completions_path' => '/codex/responses',
                    'models' => [
                        'gpt-5.6-sol' => [
                            'id' => 'gpt-5.6-sol',
                            'name' => 'GPT-5.6 Sol',
                            'context_window' => 272000,
                            'max_tokens' => 128000,
                            'input' => ['text', 'image'],
                            'tool_calling' => true,
                            'reasoning' => true,
                        ],
                        'gpt-5.6-luna' => [
                            'id' => 'gpt-5.6-luna',
                            'name' => 'GPT-5.6 Luna',
                            'context_window' => 272000,
                            'max_tokens' => 128000,
                            'input' => ['text', 'image'],
                            'tool_calling' => true,
                            'reasoning' => true,
                        ],
                    ],
                ],
            ],
        ];
    }

    private function createResolver(array $aiData): SessionAwareModelResolver
    {
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
            ),
            entityManager: $this->entityManager,
        );
        $sessionMetaStore = $hatfieldSessionStore;

        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new SettingsOverrideWriter($pathResolver, PropertyAccess::createPropertyAccessor(), new Filesystem());
        $appConfig = $this->makeAppConfig($aiData);
        $selectionService = new ModelSelectionService($appConfig, new ModelResolver($appConfig, $sessionMetaStore), $homeWriter, $sessionMetaStore);

        $catalog = $appConfig->catalog ?? new HatfieldModelCatalog(new AiConfig(defaultModel: '', defaultReasoning: 'medium', providers: []));

        return new SessionAwareModelResolver($selectionService, $catalog, $sessionMetaStore);
    }

    private function makeAppConfig(array $aiData): AppConfig
    {
        $raw = ['tui' => ['theme' => 'cyberpunk']];
        if ([] !== $aiData) {
            $raw['ai'] = $aiData;
        }

        $ai = AiConfig::optionalFromArray($raw);

        return new AppConfig(
            tui: new TuiConfig(theme: (string) (($raw['tui'] ?? [])['theme'] ?? 'cyberpunk')),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            ai: $ai,
            raw: $raw,
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: getcwd() ?: '/',
        );
    }

    /**
     * Create a session entity and apply metadata.
     *
     * No public_id column — the integer primary key is the canonical
     * identifier and its string form is the external session ID.
     * Returns the session ID as a numeric string.
     */
    private function writeSessionMetadata(string $sessionId, array $meta): string
    {
        $entity = new HatfieldSession();
        $entity->cwd = $this->tempDir.'/project';
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        $id = (string) $entity->id;

        if (isset($meta['model']) && \is_string($meta['model'])) {
            $entity->model = $meta['model'];
        }
        if (isset($meta['reasoning']) && \is_string($meta['reasoning'])) {
            $entity->reasoning = $meta['reasoning'];
        }
        if (isset($meta['name']) && \is_string($meta['name'])) {
            $entity->name = $meta['name'];
        }

        $this->entityManager->flush();

        return $id;
    }

    private function standardAiData(): array
    {
        return [
            'default_model' => 'deepseek/deepseek-v4-pro',
            'default_reasoning' => 'medium',
            'providers' => [
                'deepseek' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'https://api.deepseek.com',
                    'completions_path' => '/chat/completions',
                    'models' => [
                        'deepseek-v4-pro' => [
                            'id' => 'deepseek-v4-pro',
                            'name' => 'DeepSeek V4 Pro',
                            'context_window' => 131072,
                            'max_tokens' => 131072,
                            'input' => ['text'],
                            'reasoning' => true,
                        ],
                        'deepseek-v4-flash' => [
                            'id' => 'deepseek-v4-flash',
                            'name' => 'DeepSeek V4 Flash',
                            'context_window' => 131072,
                            'max_tokens' => 131072,
                            'input' => ['text'],
                            'reasoning' => false,
                        ],
                    ],
                ],
                'llama_cpp' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'http://192.168.2.38:8052/v1',
                    'models' => [
                        'flash' => [
                            'id' => 'flash',
                            'name' => 'Flash',
                            'context_window' => 200000,
                            'max_tokens' => 65536,
                            'input' => ['text', 'image'],
                            'reasoning' => false,
                        ],
                    ],
                ],
            ],
        ];
    }
}
