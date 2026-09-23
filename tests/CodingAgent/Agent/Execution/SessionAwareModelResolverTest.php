<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Agent\Execution\RunStartedMetadataReader;
use Ineersa\CodingAgent\Agent\Execution\SessionAwareModelResolver;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
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
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
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
        TestDirectoryIsolation::createHatfieldTree($this->homeDir);
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
            false,
            new ModelInvocationInput(runId: $sessionId),
            new ModelResolutionOptions(),
        );

        $this->assertSame('llama_cpp/flash', $result->model);
        $this->assertSame('llama_cpp', $result->providerId);
        $this->assertSame('medium', $result->reasoning);
        $this->assertSame([], $result->providerOptions);
    }

    public function testCodexMapsStableSessionCacheIdentityToProviderPromptCacheKey(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $sessionId = $this->writeSessionMetadata('sess-codex', ['model' => 'openai-codex/gpt-test']);

        $result = $resolver->resolve(
            '',
            false,
            new ModelInvocationInput(runId: $sessionId),
            new ModelResolutionOptions(),
        );

        $this->assertSame(['prompt_cache_key'], array_keys($result->providerOptions));
        $this->assertInstanceOf(UuidV7::class, Uuid::fromString($result->providerOptions['prompt_cache_key']));
    }

    public function testCodexMapsEphemeralUuidV7RunToProviderPromptCacheKey(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $runId = UuidV7::v7()->toRfc4122();

        $result = $resolver->resolve(
            'openai-codex/gpt-test',
            false,
            new ModelInvocationInput(runId: $runId),
            new ModelResolutionOptions(),
        );

        $this->assertSame(['prompt_cache_key' => $runId], $result->providerOptions);
    }

    public function testGrokMapsSessionIdToProviderPromptCacheKey(): void
    {
        $aiData = $this->standardAiData();
        $aiData['providers']['xai'] = [
            'type' => 'grok',
            'enabled' => true,
            'models' => [
                'grok-composer' => [
                    'id' => 'grok-composer',
                    'name' => 'Grok Composer',
                    'context_window' => 128000,
                    'max_tokens' => 32768,
                    'input' => ['text'],
                    'reasoning' => false,
                ],
            ],
        ];
        $resolver = $this->createResolver($aiData);
        $sessionId = $this->writeSessionMetadata('sess-grok', ['model' => 'xai/grok-composer']);

        $result = $resolver->resolve(
            '',
            false,
            new ModelInvocationInput(runId: $sessionId),
            new ModelResolutionOptions(),
        );

        $this->assertSame(['prompt_cache_key' => $sessionId], $result->providerOptions);
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
            false,
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
            false,
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
            false,
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
            false,
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
            false,
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
            false,
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
            false,
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
            false,
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
            false,
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
            false,
            new ModelInvocationInput(runId: $childRunId),
            new ModelResolutionOptions(),
        );

        $this->assertSame('deepseek/deepseek-v4-pro', $result->model);
        $this->assertSame([], $result->providerOptions);
    }

    public function testChildRunStartedMetadataModelAndReasoningSelected(): void
    {
        $childRunId = UuidV7::v7()->toRfc4122();

        $eventStore = new InMemoryEventStore();
        $eventStore->seed(new RunEvent(
            runId: $childRunId,
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'step_id' => 'start-child',
                'payload' => [
                    'messages' => [],
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'parent_run_id' => '1',
                            'agent_name' => 'scout',
                            'artifact_id' => 'agent_abc123',
                        ],
                        'model' => 'llama_cpp/flash',
                        'reasoning' => 'high',
                        'tools_scope' => ['allowed_tools' => []],
                    ],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        ));
        $reader = new RunStartedMetadataReader($eventStore, AttributeSerializerValidatorTestFactory::denormalizer());

        // Child runs keep their RunStarted definition model/reasoning instead
        // of the defaults (deepseek-v4-pro/medium) and have no session row, so
        // no provider_cache_key is resolved.
        $resolver = $this->createResolver($this->standardAiData(), $reader);
        $result = $resolver->resolve(
            '',
            false,
            new ModelInvocationInput(runId: $childRunId),
            new ModelResolutionOptions(),
        );

        $this->assertSame('llama_cpp/flash', $result->model);
        $this->assertSame('llama_cpp', $result->providerId);
        $this->assertSame('high', $result->reasoning);
        $this->assertSame([], $result->providerOptions);
    }

    public function testEphemeralHexRunWithoutSessionRowResolvesWithoutProviderCacheKey(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $result = $resolver->resolve(
            '',
            false,
            new ModelInvocationInput(runId: 'db1f3c6bdccc'),
            new ModelResolutionOptions(),
        );

        $this->assertSame([], $result->providerOptions);
    }

    public function testMissingNumericSessionMetadataThrows(): void
    {
        $resolver = $this->createResolver($this->standardAiData());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Session "42" has no metadata for model resolution.');

        $resolver->resolve(
            '',
            false,
            new ModelInvocationInput(runId: '42'),
            new ModelResolutionOptions(),
        );
    }

    public function testNumericSessionWithNullProviderCacheKeyInDatabaseThrowsExplicitRuntimeException(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $sessionId = $this->writeSessionMetadata('sess-null-key', ['model' => 'openai-codex/gpt-test']);

        $this->entityManager->getConnection()->executeStatement(
            'UPDATE hatfield_session SET provider_cache_key = NULL WHERE id = ?',
            [(int) $sessionId],
        );
        $this->entityManager->clear();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(\sprintf('Session "%s" is missing a provider_cache_key.', $sessionId));

        $resolver->resolve(
            '',
            false,
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
            false,
            new ModelInvocationInput(),
            new ModelResolutionOptions(),
        );

        $this->assertSame('off', $result->reasoning);
        $this->assertSame(['thinking' => ['type' => 'disabled']], $result->reasoningOptions);
        $this->assertContains('reasoning', $result->compatFeatures);
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    public function testAstraBaselineSurvivesWorkerRecreationAndResetsForNewEpoch(): void
    {
        $data = $this->standardAiData();
        $data['providers']['openai-codex']['compatibility'] = ['thinking_format' => 'codex'];
        $data['providers']['openai-codex']['models']['gpt-6-astra'] = [
            'name' => 'Astra', 'reasoning' => true,
            'thinking_level_map' => ['low' => 'low', 'medium' => 'medium', 'high' => 'high'],
            'compatibility' => ['supports_reasoning_configuration_updates' => true],
        ];
        $id = $this->writeSessionMetadata('astra', ['model' => 'openai-codex/gpt-6-astra', 'reasoning' => 'medium']);
        $store = static::getContainer()->get(HatfieldSessionStore::class);
        $input = new ModelInvocationInput(runId: $id);
        $resolver = $this->createResolver($data);
        $resolver->resolve('', false, $input, new ModelResolutionOptions());
        $this->assertNull($store->findSession($id)->reasoningBaseline);

        $first = $resolver->resolve('', true, $input, new ModelResolutionOptions());
        $this->assertSame(['reasoning' => ['effort' => 'medium', 'summary' => 'auto'], 'codex_reasoning_reset' => true], $first->reasoningOptions);
        $unchanged = $this->createResolver($data)->resolve('', true, $input, new ModelResolutionOptions());
        $this->assertSame(['reasoning' => ['effort' => 'medium', 'summary' => 'auto']], $unchanged->reasoningOptions);

        $store->updateMetadata($id, ['reasoning' => 'high']);
        $this->entityManager->clear();
        $toHigh = $this->createResolver($data)->resolve('', true, $input, new ModelResolutionOptions());
        $this->assertSame('medium', $toHigh->reasoningOptions['reasoning']['effort']);
        $this->assertSame('high', $toHigh->reasoningOptions['codex_reasoning_update']);
        $this->assertSame($id, $toHigh->reasoningOptions['hatfield_run_id']);
        $this->assertSame('openai-codex/gpt-6-astra', $toHigh->reasoningOptions['hatfield_model_ref']);
        $this->assertArrayNotHasKey('hatfield_run_id', $toHigh->providerOptions);
        $this->assertArrayNotHasKey('hatfield_model_ref', $toHigh->providerOptions);
        $store->rememberReasoningTransition($id, 'openai-codex/gpt-6-astra', 'transition-high', 'high');

        $store->updateMetadata($id, ['reasoning' => 'high']);
        $this->entityManager->clear();
        $stillHigh = $this->createResolver($data)->resolve('', true, $input, new ModelResolutionOptions());
        $this->assertSame(['reasoning' => ['effort' => 'medium', 'summary' => 'auto']], $stillHigh->reasoningOptions);
        $this->assertArrayNotHasKey('hatfield_run_id', $stillHigh->reasoningOptions);
        $this->assertArrayNotHasKey('hatfield_model_ref', $stillHigh->reasoningOptions);

        $store->updateMetadata($id, ['reasoning' => 'low']);
        $this->entityManager->clear();
        $toLow = $this->createResolver($data)->resolve('', true, $input, new ModelResolutionOptions());
        $this->assertSame('medium', $toLow->reasoningOptions['reasoning']['effort']);
        $this->assertSame('low', $toLow->reasoningOptions['codex_reasoning_update']);
        $store->rememberReasoningTransition($id, 'openai-codex/gpt-6-astra', 'transition-low', 'low');

        $store->updateMetadata($id, ['reasoning' => 'medium']);
        $this->entityManager->clear();
        $backToBaseline = $this->createResolver($data)->resolve('', true, $input, new ModelResolutionOptions());
        $this->assertSame('medium', $backToBaseline->reasoningOptions['reasoning']['effort']);
        $this->assertSame('medium', $backToBaseline->reasoningOptions['codex_reasoning_update']);
        $store->rememberReasoningTransition($id, 'openai-codex/gpt-6-astra', 'transition-medium', 'medium');

        $store->updateMetadata($id, ['reasoning' => 'low']);
        $this->entityManager->clear();
        $toLowAgain = $this->createResolver($data)->resolve('', true, $input, new ModelResolutionOptions());
        $this->assertSame('medium', $toLowAgain->reasoningOptions['reasoning']['effort']);
        $this->assertSame('low', $toLowAgain->reasoningOptions['codex_reasoning_update']);
        $store->rememberReasoningTransition($id, 'openai-codex/gpt-6-astra', 'transition-low-again', 'low');

        $override = $resolver->resolve('openai-codex/gpt-6-astra', true, $input, new ModelResolutionOptions(['thinking_level' => 'high']));
        $this->assertSame(['reasoning' => ['effort' => 'high', 'summary' => 'auto']], $override->reasoningOptions);
        $this->assertSame('medium', $store->findSession($id)->reasoningBaseline['effort']);

        $store->resetReasoningBaseline($id);
        $resumed = $this->createResolver($data)->resolve('', true, $input, new ModelResolutionOptions());
        $this->assertSame(['reasoning' => ['effort' => 'low', 'summary' => 'auto'], 'codex_reasoning_reset' => true], $resumed->reasoningOptions);
        $store->updateMetadata($id, ['model' => 'openai-codex/gpt-test']);
        $this->assertNull($store->findSession($id)->reasoningBaseline);
        $store->updateMetadata($id, ['model' => 'openai-codex/gpt-6-astra', 'reasoning' => 'high']);
        $changed = $this->createResolver($data)->resolve('', true, $input, new ModelResolutionOptions());
        $this->assertSame(['reasoning' => ['effort' => 'high', 'summary' => 'auto'], 'codex_reasoning_reset' => true], $changed->reasoningOptions);
    }

    public function testConfigurationUpdateBaselineAppliesToNonAstraGpt6Models(): void
    {
        $data = $this->standardAiData();
        $data['providers']['openai-codex']['compatibility'] = ['thinking_format' => 'codex'];
        $data['providers']['openai-codex']['models']['gpt-6-sol'] = [
            'name' => 'Sol', 'reasoning' => true,
            'thinking_level_map' => ['off' => 'none', 'low' => 'low', 'medium' => 'medium', 'high' => 'high'],
            'compatibility' => ['supports_reasoning_configuration_updates' => true],
        ];
        $id = $this->writeSessionMetadata('sol', ['model' => 'openai-codex/gpt-6-sol', 'reasoning' => 'medium']);
        $store = static::getContainer()->get(HatfieldSessionStore::class);
        $input = new ModelInvocationInput(runId: $id);
        $resolver = $this->createResolver($data);

        $first = $resolver->resolve('', true, $input, new ModelResolutionOptions());
        $this->assertSame(['reasoning' => ['effort' => 'medium', 'summary' => 'auto'], 'codex_reasoning_reset' => true], $first->reasoningOptions);

        $store->updateMetadata($id, ['reasoning' => 'high']);
        $this->entityManager->clear();
        $toHigh = $this->createResolver($data)->resolve('', true, $input, new ModelResolutionOptions());
        $this->assertSame('medium', $toHigh->reasoningOptions['reasoning']['effort']);
        $this->assertSame('high', $toHigh->reasoningOptions['codex_reasoning_update']);
        $this->assertSame('openai-codex/gpt-6-sol', $toHigh->reasoningOptions['hatfield_model_ref']);
    }

    private function createResolver(array $aiData, ?RunStartedMetadataReader $childMetadataReader = null): SessionAwareModelResolver
    {
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
            ),
            entityManager: $this->entityManager,
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
        $sessionMetaStore = $hatfieldSessionStore;

        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new SettingsOverrideWriter($pathResolver, PropertyAccess::createPropertyAccessor(), new Filesystem());
        $appConfig = $this->makeAppConfig($aiData);
        $selectionService = new ModelSelectionService($appConfig, new ModelResolver($appConfig, $sessionMetaStore, new \Psr\Log\NullLogger()), $homeWriter, $sessionMetaStore);

        $catalog = $appConfig->catalog ?? new HatfieldModelCatalog(new AiConfig(defaultModel: '', defaultReasoning: 'medium', providers: []));

        return new SessionAwareModelResolver($selectionService, $catalog, $sessionMetaStore, $childMetadataReader);
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
                'openai-codex' => [
                    'type' => 'codex',
                    'enabled' => true,
                    'models' => [
                        'gpt-test' => [
                            'id' => 'gpt-test',
                            'name' => 'GPT Test',
                            'context_window' => 128000,
                            'max_tokens' => 32768,
                            'input' => ['text'],
                            'reasoning' => true,
                        ],
                    ],
                ],
                'llama_cpp' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'http://10.0.0.89:8052/v1',
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
