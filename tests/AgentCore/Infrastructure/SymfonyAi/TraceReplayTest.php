<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ModelResolverRoutingSubscriber;
use Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi\Replay\FixtureReplayModelClient;
use Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi\Replay\FixtureReplayResultConverter;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionAwareModelResolver;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface as SymfonyPlatformInterface;
use Symfony\AI\Platform\Provider;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Application-level trace/replay tests.
 *
 * Exercises the full LlmPlatformAdapter path with fixture-driven provider data
 * and real model resolution from session metadata. The only faked boundary is
 * the Symfony AI ModelClient (the actual HTTP call to the provider API).
 */
final class TraceReplayTest extends KernelTestCase
{
    private string $tempDir;
    private string $homeDir;
    private SessionMetadataStore $sessionMetaStore;
    private \Doctrine\ORM\EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/hatfield-trace-replay-'.uniqid('', true);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir.'/.hatfield', 0777, true);
        mkdir($this->tempDir.'/project/.hatfield/sessions', 0777, true);
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");

        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine.orm.default_entity_manager');
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
            ),
            entityManager: $this->entityManager,
        );
        $this->sessionMetaStore = new SessionMetadataStore($hatfieldSessionStore);
    }

    protected function tearDown(): void
    {
        // Let KernelTestCase::tearDown() handle kernel shutdown.
        // It calls ensureKernelShutdown() which may re-boot the kernel,
        // which re-registers the exception handler.
        parent::tearDown();
        // Pop the exception handler that FrameworkBundle::boot() registered
        // during kernel boot/shutdown. The error handler is automatically
        // popped by ErrorHandler::register() because $replace=false.
        restore_exception_handler();
        $this->removeDir($this->tempDir);
    }

    // ──────────────────────────────────────────────
    //  Test 1: Fixture replay — adapter processes
    //  recorded deltas with session metadata model
    //  resolution.
    // ──────────────────────────────────────────────

    public function testReplayFixtureProducesCorrectAssistantMessage(): void
    {
        $fixture = $this->loadFixture('successful-response.json');

        // Create session metadata with model and reasoning
        $replaySessId = $this->writeSessionMetadata('', [
            'model' => $fixture['model'],
            'reasoning' => $fixture['reasoning'],
        ]);

        // Set up run store with the turn's messages
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $replaySessId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 3,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: array_map(
                static fn (array $m): AgentMessage => new AgentMessage($m['role'], [['type' => 'text', 'text' => $m['content']]]),
                $fixture['input']['messages'],
            ),
            activeStepId: 'turn-1-llm-1',
        ), 0);

        // Build real model resolution chain
        $modelResolver = $this->createSessionAwareResolver($this->standardAiData());
        $modelClient = new FixtureReplayModelClient($fixture);
        $platform = $this->createSymfonyPlatform(
            modelClient: $modelClient,
            fixture: $fixture,
            modelResolver: $modelResolver,
        );

        $adapter = new LlmPlatformAdapter(
            runStore: $runStore,
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(),
            platform: $platform,
            transformContextHooks: [],
            convertToLlmHooks: [],
            streamObserver: null,
            costCalculator: null,
            modelResolver: null,
            logger: new NullLogger(),
        );

        $result = $adapter->invoke(new ModelInvocationRequest(
            model: 'fallback/unused',
            input: new ModelInvocationInput(
                runId: $replaySessId,
                turnNo: 1,
                stepId: 'turn-1-llm-1',
            ),
        ));

        // ── Assertions ──

        // Model was resolved from session metadata (not fallback)
        $this->assertSame($fixture['model'], $modelClient->capturedModel,
            'Model should be resolved from session metadata');

        // Assistant message text matches fixture expected text
        $this->assertNotNull($result->assistantMessage, 'Assistant message should not be null');
        $this->assertSame($fixture['expected_text'], $result->assistantMessage->asText(),
            'Assistant message text should match fixture expected text');

        // Usage metadata is captured
        $this->assertSame($fixture['usage']['input_tokens'], $result->usage['input_tokens']);
        $this->assertSame($fixture['usage']['output_tokens'], $result->usage['output_tokens']);
        $this->assertSame($fixture['usage']['total_tokens'], $result->usage['total_tokens']);

        // Session metadata remains available
        $session = $this->sessionMetaStore->findSession($replaySessId);
        $this->assertNotNull($session);
        $this->assertSame($fixture['model'], $session->model ?? null);
        $this->assertSame($fixture['reasoning'], $session->reasoning ?? null);
    }

    // ──────────────────────────────────────────────
    //  Test 2: Resume uses session metadata values
    //  over changed global defaults.
    // ──────────────────────────────────────────────

    public function testResumeUsesSessionMetadataOverGlobalDefaults(): void
    {
        // Write session metadata with old model/reasoning
        $resumeSessId = $this->writeSessionMetadata('', [
            'model' => 'llama_cpp/flash',
            'reasoning' => 'off',
        ]);

        // Build selection service with DIFFERENT global defaults
        $selectionService = $this->createSelectionService([
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
                            'thinking_level_map' => [
                                'minimal' => 'minimal',
                                'low' => 'low',
                                'medium' => 'medium',
                                'high' => 'high',
                                'xhigh' => 'max',
                            ],
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
        ]);

        // Global default is deepseek/deepseek-v4-pro, but session metadata says llama_cpp/flash
        // → session metadata should win
        $resolvedModel = $selectionService->resolveInitialModel(
            explicitModel: null,
            sessionId: $resumeSessId,
        );

        $this->assertNotNull($resolvedModel);
        $this->assertSame('llama_cpp', $resolvedModel->providerId,
            'Session metadata model provider should win over global default');
        $this->assertSame('flash', $resolvedModel->modelName,
            'Session metadata model name should win over global default');

        // Reasoning should also come from session metadata
        $resolvedReasoning = $selectionService->resolveInitialReasoning(
            explicitReasoning: null,
            sessionId: $resumeSessId,
        );
        $this->assertSame('off', $resolvedReasoning,
            'Session metadata reasoning should win over global default');
    }

    // ──────────────────────────────────────────────
    //  Test 3: Model/reasoning persistence changes
    //  survive in session metadata for resume.
    // ──────────────────────────────────────────────

    public function testModelChangePersistsAcrossResume(): void
    {
        $selectionService = $this->createSelectionService($this->standardAiData());

        $persistSessId = $this->writeSessionMetadata('', []);

        // Initially no model/reasoning metadata — only identity fields exist
        $session = $this->sessionMetaStore->findSession($persistSessId);
        $this->assertNotNull($session);
        $this->assertNull($session->model, 'Model not set before change');
        $this->assertNull($session->reasoning, 'Reasoning not set before change');

        // Change model and reasoning
        $selectionService->changeModel(
            \Ineersa\CodingAgent\Config\Ai\AiModelReference::parse('deepseek/deepseek-v4-flash'),
            $persistSessId,
        );
        $selectionService->changeReasoning('low', $persistSessId);

        // Verify metadata was persisted
        $session = $this->sessionMetaStore->findSession($persistSessId);
        $this->assertNotNull($session);
        $this->assertSame('deepseek/deepseek-v4-flash', $session->model ?? null);
        $this->assertSame('deepseek', $session->modelProvider ?? null);
        $this->assertSame('deepseek-v4-flash', $session->modelName ?? null);
        $this->assertSame('low', $session->reasoning ?? null);
        $this->assertInstanceOf(\DateTimeImmutable::class, $session->updatedAt);

        // Resume resolves from persisted metadata
        $resolvedModel = $selectionService->resolveInitialModel(
            explicitModel: null,
            sessionId: $persistSessId,
        );
        $this->assertNotNull($resolvedModel);
        $this->assertSame('deepseek/deepseek-v4-flash', $resolvedModel->toString());
    }

    // ──────────────────────────────────────────────
    //  Test 4: Trace replay with thinking content
    // ──────────────────────────────────────────────

    public function testReplayFixtureWithThinkingDeltas(): void
    {
        $fixturePath = __DIR__.'/../../Fixtures/traces/successful-response.json';
        $fixture = json_decode(file_get_contents($fixturePath), true);

        // Add thinking deltas to test thinking delta handling
        $thinkingDeltas = [
            ['type' => 'thinking', 'content' => 'The user is asking about recursion.'],
            ['type' => 'thinking_delta', 'content' => 'Let me explain with clear examples.'],
        ];
        $fixture['deltas'] = array_merge($thinkingDeltas, $fixture['deltas']);
        // Use actual newlines (the fixture has literal newlines)
        $fixture['expected_text'] = "## Recursion in Programming\n\nRecursion is a technique where a function calls itself to solve smaller instances of the same problem.\n\n### Key Components\n\n1. **Base case** – a condition that stops the recursion\n2. **Recursive case** – the function calls itself with modified arguments";

        $thinkSessId = $this->writeSessionMetadata('', [
            'model' => 'deepseek/deepseek-v4-pro',
            'reasoning' => 'high',
        ]);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $thinkSessId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 3,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'Explain recursion']])],
            activeStepId: 'turn-1-llm-1',
        ), 0);

        $modelResolver = $this->createSessionAwareResolver($this->standardAiData());
        $modelClient = new FixtureReplayModelClient($fixture);
        $platform = $this->createSymfonyPlatform(
            modelClient: $modelClient,
            fixture: $fixture,
            modelResolver: $modelResolver,
        );

        $adapter = new LlmPlatformAdapter(
            runStore: $runStore,
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(),
            platform: $platform,
            transformContextHooks: [],
            convertToLlmHooks: [],
            streamObserver: null,
            costCalculator: null,
            modelResolver: null,
            logger: new NullLogger(),
        );

        $result = $adapter->invoke(new ModelInvocationRequest(
            model: 'fallback/unused',
            input: new ModelInvocationInput(
                runId: $thinkSessId,
                turnNo: 1,
                stepId: 'turn-1-llm-1',
            ),
        ));

        $this->assertNotNull($result->assistantMessage);
        $this->assertSame($fixture['expected_text'], $result->assistantMessage->asText());
        $this->assertTrue($result->assistantMessage->hasThinking(),
            'Assistant message should contain thinking content');
        $this->assertSame($fixture['usage']['total_tokens'], $result->usage['total_tokens']);
    }

    protected static function createKernel(array $options = []): \Ineersa\CodingAgent\Kernel
    {
        $env = $options['environment'] ?? 'test';
        $debug = (bool) ($options['debug'] ?? false);

        return new \Ineersa\CodingAgent\Kernel($env, $debug);
    }

    // ──────────────────────────────────────────────
    //  Common helpers
    // ──────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $name): array
    {
        $path = __DIR__.'/../../Fixtures/traces/'.$name;
        $this->assertFileExists($path, 'Fixture file not found: '.$path);

        $data = json_decode(file_get_contents($path), true);
        $this->assertIsArray($data, 'Fixture must be valid JSON');

        return $data;
    }

    private function createSessionAwareResolver(array $aiData): SessionAwareModelResolver
    {
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $appConfig = $this->makeAppConfig($aiData);
        $selectionService = new ModelSelectionService($appConfig, new \Ineersa\CodingAgent\Config\ModelResolver($appConfig, $this->sessionMetaStore), new \Ineersa\CodingAgent\Config\ModelSettingsPersister($homeWriter, $this->sessionMetaStore));
        $catalog = $appConfig->catalog
            ?? new HatfieldModelCatalog(new AiConfig(defaultModel: '', defaultReasoning: 'medium', providers: []));

        return new SessionAwareModelResolver($selectionService, $catalog, $this->sessionMetaStore);
    }

    private function createSelectionService(array $aiData): ModelSelectionService
    {
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $appConfig = $this->makeAppConfig($aiData);

        return new ModelSelectionService($appConfig, new \Ineersa\CodingAgent\Config\ModelResolver($appConfig, $this->sessionMetaStore), new \Ineersa\CodingAgent\Config\ModelSettingsPersister($homeWriter, $this->sessionMetaStore));
    }

    /**
     * @param array<string, mixed> $aiData
     */
    private function makeAppConfig(array $aiData): AppConfig
    {
        $raw = ['tui' => ['theme' => 'cyberpunk']];
        if ([] !== $aiData) {
            $raw['ai'] = $aiData;
        }

        $ai = AiConfig::optionalFromArray($raw);

        return new AppConfig(
            tui: TuiConfig::fromArray((array) ($raw['tui'] ?? [])),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            ai: $ai,
            raw: $raw,
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: getcwd() ?: '/',
        );
    }

    /**
     * Create a session entity with auto-increment ID and apply metadata.
     *
     * No public_id column — the integer primary key cast to string
     * is the session identifier.
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

        $this->entityManager->flush();

        return $id;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                chmod($file->getPathname(), 0644);
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }

    /**
     * Build a standard AI config matching the project's example settings.
     *
     * @return array<string, mixed>
     */
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
                            'tool_calling' => true,
                            'thinking_level_map' => [
                                'minimal' => 'minimal',
                                'low' => 'low',
                                'medium' => 'medium',
                                'high' => 'high',
                                'xhigh' => 'max',
                            ],
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

    /**
     * Create a real Symfony Platform wired with a fixture-driven model client
     * and the given model resolver.
     *
     * @param array<string, mixed> $fixture
     */
    private function createSymfonyPlatform(
        FixtureReplayModelClient $modelClient,
        array $fixture,
        ?\Ineersa\AgentCore\Contract\Model\ModelResolverInterface $modelResolver = null,
    ): SymfonyPlatformInterface {
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new ModelResolverRoutingSubscriber($modelResolver));

        return new Platform(
            providers: [new Provider(
                name: 'replay',
                modelClients: [$modelClient],
                resultConverters: [new FixtureReplayResultConverter($fixture)],
                modelCatalog: new FallbackModelCatalog(),
                eventDispatcher: $eventDispatcher,
            )],
            eventDispatcher: $eventDispatcher,
        );
    }
}
