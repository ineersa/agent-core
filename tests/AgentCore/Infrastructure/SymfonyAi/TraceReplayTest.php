<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Application\Pipeline\RunMessageStateTools;
use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Ineersa\AgentCore\Contract\Hook\ConvertToLlmHookInterface;
use Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ModelResolverRoutingSubscriber;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\PlatformInvocationMetadata;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionAwareModelResolver;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface as SymfonyPlatformInterface;
use Symfony\AI\Platform\PlainConverter;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Yaml\Yaml;

/**
 * Application-level trace/replay tests.
 *
 * Exercises the full LlmPlatformAdapter path with fixture-driven provider data
 * and real model resolution from session metadata. The only faked boundary is
 * the Symfony AI ModelClient (the actual HTTP call to the provider API).
 */
final class TraceReplayTest extends TestCase
{
    private string $tempDir;
    private string $homeDir;
    private SessionMetadataStore $sessionMetaStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/hatfield-trace-replay-'.uniqid('', true);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir.'/.hatfield', 0777, true);
        mkdir($this->tempDir.'/project/.hatfield/sessions', 0777, true);
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");

        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
            ),
            lockFactory: new LockFactory(new FlockStore()),
        );
        $this->sessionMetaStore = new SessionMetadataStore($hatfieldSessionStore);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
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
        $this->writeSessionMetadata('replay-session-1', [
            'model' => $fixture['model'],
            'reasoning' => $fixture['reasoning'],
        ]);

        // Set up run store with the turn's messages
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: 'replay-session-1',
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
        );

        $result = $adapter->invoke(new ModelInvocationRequest(
            model: 'fallback/unused',
            input: new ModelInvocationInput(
                runId: 'replay-session-1',
                turnNo: 1,
                stepId: 'turn-1-llm-1',
            ),
        ));

        // ── Assertions ──

        // Model was resolved from session metadata (not fallback)
        self::assertSame($fixture['model'], $modelClient->capturedModel,
            'Model should be resolved from session metadata');

        // Assistant message text matches fixture expected text
        self::assertNotNull($result->assistantMessage, 'Assistant message should not be null');
        self::assertSame($fixture['expected_text'], $result->assistantMessage->asText(),
            'Assistant message text should match fixture expected text');

        // Usage metadata is captured
        self::assertSame($fixture['usage']['input_tokens'], $result->usage['input_tokens']);
        self::assertSame($fixture['usage']['output_tokens'], $result->usage['output_tokens']);
        self::assertSame($fixture['usage']['total_tokens'], $result->usage['total_tokens']);

        // Session metadata remains available
        $meta = $this->sessionMetaStore->readSessionMetadata('replay-session-1');
        self::assertSame($fixture['model'], $meta['model'] ?? null);
        self::assertSame($fixture['reasoning'], $meta['reasoning'] ?? null);
    }

    // ──────────────────────────────────────────────
    //  Test 2: Resume uses session metadata values
    //  over changed global defaults.
    // ──────────────────────────────────────────────

    public function testResumeUsesSessionMetadataOverGlobalDefaults(): void
    {
        // Write session metadata with old model/reasoning
        $this->writeSessionMetadata('resume-session-1', [
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
            sessionId: 'resume-session-1',
        );

        self::assertNotNull($resolvedModel);
        self::assertSame('llama_cpp', $resolvedModel->providerId,
            'Session metadata model provider should win over global default');
        self::assertSame('flash', $resolvedModel->modelName,
            'Session metadata model name should win over global default');

        // Reasoning should also come from session metadata
        $resolvedReasoning = $selectionService->resolveInitialReasoning(
            explicitReasoning: null,
            sessionId: 'resume-session-1',
        );
        self::assertSame('off', $resolvedReasoning,
            'Session metadata reasoning should win over global default');
    }

    // ──────────────────────────────────────────────
    //  Test 3: Model/reasoning persistence changes
    //  survive in session metadata for resume.
    // ──────────────────────────────────────────────

    public function testModelChangePersistsAcrossResume(): void
    {
        $selectionService = $this->createSelectionService($this->standardAiData());

        // Initially no session metadata
        $meta = $this->sessionMetaStore->readSessionMetadata('persist-session-1');
        self::assertSame([], $meta, 'No metadata before first change');

        // Change model and reasoning
        $selectionService->changeModel(
            \Ineersa\CodingAgent\Config\Ai\AiModelReference::parse('deepseek/deepseek-v4-flash'),
            'persist-session-1',
        );
        $selectionService->changeReasoning('low', 'persist-session-1');

        // Verify metadata was persisted
        $meta = $this->sessionMetaStore->readSessionMetadata('persist-session-1');
        self::assertSame('deepseek/deepseek-v4-flash', $meta['model'] ?? null);
        self::assertSame('deepseek', $meta['model_provider'] ?? null);
        self::assertSame('deepseek-v4-flash', $meta['model_name'] ?? null);
        self::assertSame('low', $meta['reasoning'] ?? null);
        self::assertArrayHasKey('updated_at', $meta);

        // Resume resolves from persisted metadata
        $resolvedModel = $selectionService->resolveInitialModel(
            explicitModel: null,
            sessionId: 'persist-session-1',
        );
        self::assertNotNull($resolvedModel);
        self::assertSame('deepseek/deepseek-v4-flash', $resolvedModel->toString());
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

        $this->writeSessionMetadata('replay-thinking-session', [
            'model' => 'deepseek/deepseek-v4-pro',
            'reasoning' => 'high',
        ]);

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: 'replay-thinking-session',
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
        );

        $result = $adapter->invoke(new ModelInvocationRequest(
            model: 'fallback/unused',
            input: new ModelInvocationInput(
                runId: 'replay-thinking-session',
                turnNo: 1,
                stepId: 'turn-1-llm-1',
            ),
        ));

        self::assertNotNull($result->assistantMessage);
        self::assertSame($fixture['expected_text'], $result->assistantMessage->asText());
        self::assertTrue($result->assistantMessage->hasThinking(),
            'Assistant message should contain thinking content');
        self::assertSame($fixture['usage']['total_tokens'], $result->usage['total_tokens']);
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
        self::assertFileExists($path, 'Fixture file not found: '.$path);

        $data = json_decode(file_get_contents($path), true);
        self::assertIsArray($data, 'Fixture must be valid JSON');

        return $data;
    }

    private function createSessionAwareResolver(array $aiData): SessionAwareModelResolver
    {
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $appConfig = $this->makeAppConfig($aiData);
        $selectionService = new ModelSelectionService($appConfig, $homeWriter, $this->sessionMetaStore);

        return new SessionAwareModelResolver($selectionService);
    }

    private function createSelectionService(array $aiData): ModelSelectionService
    {
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $appConfig = $this->makeAppConfig($aiData);

        return new ModelSelectionService($appConfig, $homeWriter, $this->sessionMetaStore);
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
            sessions: (array) ($raw['sessions'] ?? []),
            ai: $ai,
            raw: $raw,
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: getcwd() ?: '/',
        );
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function writeSessionMetadata(string $sessionId, array $meta): void
    {
        $dir = $this->tempDir.'/project/.hatfield/sessions/'.$sessionId;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir.'/metadata.yaml', Yaml::dump($meta, 4, 2));
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
     * @param array<string, mixed>           $fixture
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

// ──────────────────────────────────────────────
//  Fixture replay helper classes (test-only)
// ──────────────────────────────────────────────

/**
 * ModelClient that records the model name and returns fixture usage data.
 *
 * The real HTTP call to the provider API is replaced by this stub;
 * the DeferredResult's stream is produced by FixtureReplayResultConverter.
 */
final class FixtureReplayModelClient implements ModelClientInterface
{
    public ?string $capturedModel = null;

    /** @var array<string, mixed> */
    public array $capturedOptions = [];

    /** @var array<string, mixed> $fixture */
    private array $fixture;

    /**
     * @param array<string, mixed> $fixture
     */
    public function __construct(array $fixture)
    {
        $this->fixture = $fixture;
    }

    public function supports(Model $model): bool
    {
        return true;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        $this->capturedModel = $model->getName();
        $this->capturedOptions = $options;

        return new InMemoryRawResult([
            'token_usage' => new FixtureTokenUsage(
                promptTokens: $this->fixture['usage']['input_tokens'] ?? null,
                completionTokens: $this->fixture['usage']['output_tokens'] ?? null,
                totalTokens: $this->fixture['usage']['total_tokens'] ?? null,
            ),
        ]);
    }
}

/**
 * Stream result converter that produces deltas from fixture data.
 *
 * Converts fixture delta entries (type: text, thinking, etc.) into
 * the corresponding Symfony AI DeltaInterface objects so they can
 * be consumed by LlmPlatformAdapter::consumeStream().
 */
final class FixtureReplayResultConverter implements ResultConverterInterface
{
    /** @var array<string, mixed> $fixture */
    private array $fixture;

    /**
     * @param array<string, mixed> $fixture
     */
    public function __construct(array $fixture)
    {
        $this->fixture = $fixture;
    }

    public function supports(Model $model): bool
    {
        return true;
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        unset($result, $options);

        return new StreamResult((function (): \Generator {
            foreach ($this->fixture['deltas'] ?? [] as $delta) {
                $type = $delta['type'] ?? 'text';
                $content = $delta['content'] ?? '';

                yield match ($type) {
                    'text' => new TextDelta($content),
                    'thinking' => new \Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta($content),
                    'thinking_delta' => new \Symfony\AI\Platform\Result\Stream\Delta\ThinkingDelta($content),
                    default => new TextDelta($content),
                };
            }
        })());
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return new class ($this->fixture) implements TokenUsageExtractorInterface {
            /** @param array<string, mixed> $fixture */
            public function __construct(private array $fixture)
            {
            }

            public function extract(RawResultInterface $rawResult, array $options = []): ?TokenUsageInterface
            {
                unset($options);

                $data = $rawResult->getData();
                $tokenUsage = $data['token_usage'] ?? null;

                return $tokenUsage instanceof TokenUsageInterface ? $tokenUsage : null;
            }
        };
    }
}

/**
 * Token usage DTO that returns fixture usage values.
 */
final readonly class FixtureTokenUsage implements TokenUsageInterface
{
    public function __construct(
        private ?int $promptTokens = null,
        private ?int $completionTokens = null,
        private ?int $totalTokens = null,
    ) {
    }

    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }

    public function getThinkingTokens(): ?int
    {
        return null;
    }

    public function getToolTokens(): ?int
    {
        return null;
    }

    public function getCachedTokens(): ?int
    {
        return null;
    }

    public function getCacheCreationTokens(): ?int
    {
        return null;
    }

    public function getCacheReadTokens(): ?int
    {
        return null;
    }

    public function getRemainingTokens(): ?int
    {
        return null;
    }

    public function getRemainingTokensMinute(): ?int
    {
        return null;
    }

    public function getRemainingTokensMonth(): ?int
    {
        return null;
    }

    public function getTotalTokens(): ?int
    {
        return $this->totalTokens;
    }
}
