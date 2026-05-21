<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Model\ProviderRegistryInterface;
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
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionAwareModelResolver;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ProjectedSymfonyModelCatalog;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface as SymfonyPlatformInterface;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Yaml\Yaml;

/**
 * Opt-in real llama.cpp smoke test.
 *
 * Proves the configured generic provider can call a real llama.cpp
 * OpenAI chat-completions-style endpoint. The default PHPUnit suite excludes
 * this group; run it explicitly with `castor test:llm-real`.
 */
#[Group('llm-real')]
final class LlamaCppSmokeTest extends TestCase
{
    private string $tempDir;
    private string $homeDir;
    private string $sessionId;
    private SessionMetadataStore $sessionMetaStore;

    protected function setUp(): void
    {
        parent::setUp();

        if (false === getenv('LLAMA_CPP_SMOKE_TEST') || '' === getenv('LLAMA_CPP_SMOKE_TEST')) {
            self::markTestSkipped(
                'LLAMA_CPP_SMOKE_TEST is not set. Run `castor test:llm-real` or set '
                .'LLAMA_CPP_SMOKE_TEST=1 to enable the real llama.cpp smoke test.'
            );
        }

        $this->tempDir = sys_get_temp_dir().'/hatfield-llamacpp-'.uniqid('', true);
        $this->homeDir = $this->tempDir.'/home';
        $this->sessionId = 'llamacpp-smoke-'.uniqid('', true);

        mkdir($this->homeDir.'/.hatfield', 0777, true);
        mkdir($this->tempDir.'/project/.hatfield/sessions/'.$this->sessionId, 0777, true);

        // Minimal home settings (no ai section — we use test-specific ai data directly)
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");

        // Session metadata store
        $this->sessionMetaStore = new SessionMetadataStore();
        $this->sessionMetaStore->setSessionsBasePath($this->tempDir.'/project/.hatfield/sessions');
    }

    protected function tearDown(): void
    {
        if (isset($this->tempDir) && '' !== $this->tempDir) {
            $this->removeDir($this->tempDir);
        }
        parent::tearDown();
    }

    public function testRealLlamaCppInvocation(): void
    {
        $llamaCpp = $this->resolveLlamaCppSettings();
        $baseUrl = $llamaCpp['base_url'];
        $modelName = $llamaCpp['model'];
        $apiKey = $llamaCpp['api_key'];
        $completionsPath = $llamaCpp['completions_path'];
        $modelRef = 'llama_cpp/'.$modelName;

        // ── Session metadata: pre-set model and reasoning ──
        $this->writeSessionMetadata($this->sessionId, [
            'model' => $modelRef,
            'reasoning' => 'off',
        ]);

        // ── Real model resolution from session metadata ──
        $appConfig = $this->makeAppConfig($modelRef, $modelName, $baseUrl, $apiKey, $completionsPath);
        $modelResolver = $this->createSessionAwareResolver($appConfig);
        $eventDispatcher = new EventDispatcher();

        $providerConfig = $appConfig->catalog?->getProvider('llama_cpp');
        self::assertNotNull($providerConfig, 'Expected llama_cpp provider in test AppConfig');
        $modelDefinition = $providerConfig->models[$modelName] ?? null;
        self::assertNotNull($modelDefinition, 'Expected configured llama_cpp model in test AppConfig');

        // ── Build the real Platform with a live Provider ──
        $provider = GenericFactory::createProvider(
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            httpClient: null,
            modelCatalog: new ProjectedSymfonyModelCatalog([$modelName => $modelDefinition]),
            eventDispatcher: $eventDispatcher,
            supportsCompletions: true,
            supportsEmbeddings: false,
            completionsPath: $completionsPath,
            name: 'llama_cpp',
        );

        $eventDispatcher->addSubscriber(new ModelResolverRoutingSubscriber(
            $modelResolver,
            new class($provider) implements ProviderRegistryInterface {
                public function __construct(private readonly ProviderInterface $provider)
                {
                }

                public function get(string $id): ?ProviderInterface
                {
                    return 'llama_cpp' === $id ? $this->provider : null;
                }
            },
        ));

        $platform = new Platform(
            providers: [$provider],
            eventDispatcher: $eventDispatcher,
        );

        // ── Run store: simple turn with deterministic prompt ──
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $this->sessionId,
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 1,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [
                new AgentMessage('user', [['type' => 'text', 'text' => 'Respond with exactly one word: hello.']]),
            ],
            activeStepId: 'turn-1-llm-1',
        ), 0);

        // ── Adapter ──
        $adapter = new LlmPlatformAdapter(
            runStore: $runStore,
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(),
            platform: $platform,
            transformContextHooks: [],
            convertToLlmHooks: [],
        );

        // ── Invoke ──
        $result = $adapter->invoke(new ModelInvocationRequest(
            model: 'fallback/unused',
            input: new ModelInvocationInput(
                runId: $this->sessionId,
                turnNo: 1,
                stepId: 'turn-1-llm-1',
            ),
        ));

        // ── Assertions ──
        self::assertNotNull($result->assistantMessage, 'Expected a non-null assistant message');
        $text = $result->assistantMessage->asText();
        self::assertNotEmpty($text, 'Expected non-empty assistant text from llama.cpp');

        // Verify usage if the provider returned it (do not fail if absent)
        if (isset($result->usage['total_tokens'])) {
            self::assertGreaterThan(0, $result->usage['total_tokens']);
        }
        if (isset($result->usage['input_tokens'])) {
            self::assertGreaterThan(0, $result->usage['input_tokens']);
        }

        // Session metadata is still intact — model resolution used it correctly
        $meta = $this->sessionMetaStore->readSessionMetadata($this->sessionId);
        self::assertSame($modelRef, $meta['model'] ?? null,
            'Session metadata model should be preserved after invocation');
    }

    // ── Helpers ──

    /**
     * Build a SessionAwareModelResolver whose Hatfield catalog includes
     * the target llama_cpp provider with the given model, and whose
     * session metadata has been pre-written.
     */
    private function createSessionAwareResolver(AppConfig $appConfig): SessionAwareModelResolver
    {
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $selectionService = new ModelSelectionService($appConfig, $homeWriter, $this->sessionMetaStore);

        return new SessionAwareModelResolver($selectionService);
    }

    /**
     * Build an AppConfig with only the llama_cpp provider configured.
     */
    private function makeAppConfig(
        string $modelRef,
        string $modelName,
        string $baseUrl,
        string $apiKey,
        string $completionsPath,
    ): AppConfig {
        $aiData = [
            'default_model' => $modelRef,
            'default_reasoning' => 'off',
            'providers' => [
                'llama_cpp' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => $baseUrl,
                    'api_key' => $apiKey,
                    'completions_path' => $completionsPath,
                    'supports_completions' => true,
                    'supports_embeddings' => false,
                    'models' => [
                        $modelName => [
                            'id' => $modelName,
                            'name' => ucfirst($modelName),
                            'context_window' => 200000,
                            'max_tokens' => 65536,
                            'input' => ['text'],
                            'reasoning' => false,
                        ],
                    ],
                ],
            ],
        ];

        $raw = ['tui' => ['theme' => 'cyberpunk'], 'ai' => $aiData];
        $ai = AiConfig::optionalFromArray($raw);

        return new AppConfig(
            tui: TuiConfig::fromArray((array) ($raw['tui'] ?? [])),
            sessions: [],
            ai: $ai,
            raw: $raw,
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: getcwd() ?: '/',
        );
    }

    /**
     * Resolve llama.cpp connection settings from env overrides first, then
     * project Hatfield settings. The Castor task sets LLAMA_CPP_SMOKE_TEST=1,
     * so `castor test:llm-real` runs against the committed project config by default.
     *
     * @return array{base_url: string, model: string, api_key: string, completions_path: string}
     */
    private function resolveLlamaCppSettings(): array
    {
        $settings = [];
        $settingsPath = getcwd().'/.hatfield/settings.yaml';
        if (is_readable($settingsPath)) {
            $parsed = Yaml::parseFile($settingsPath);
            $settings = \is_array($parsed) ? $parsed : [];
        }

        $provider = $settings['ai']['providers']['llama_cpp'] ?? [];
        $provider = \is_array($provider) ? $provider : [];

        $baseUrl = getenv('LLAMA_CPP_BASE_URL') ?: (string) ($provider['base_url'] ?? '');
        if ('' === $baseUrl) {
            self::markTestSkipped(
                'No llama.cpp base URL configured. Set LLAMA_CPP_BASE_URL or configure '
                .'ai.providers.llama_cpp.base_url in .hatfield/settings.yaml.'
            );
        }

        $models = isset($provider['models']) && \is_array($provider['models']) ? $provider['models'] : [];
        $defaultModel = (string) ($settings['ai']['default_model'] ?? '');
        $modelFromDefault = str_starts_with($defaultModel, 'llama_cpp/') ? substr($defaultModel, 10) : '';
        $firstConfiguredModel = array_key_first($models);

        $model = getenv('LLAMA_CPP_MODEL')
            ?: $modelFromDefault
            ?: (\is_string($firstConfiguredModel) ? $firstConfiguredModel : 'flash');

        $apiKey = getenv('LLAMA_CPP_API_KEY') ?: $this->resolveSecret((string) ($provider['api_key'] ?? 'dummy'));
        $completionsPath = (string) ($provider['completions_path'] ?? '/chat/completions');

        return [
            'base_url' => $baseUrl,
            'model' => $model,
            'api_key' => '' !== $apiKey ? $apiKey : 'dummy',
            'completions_path' => '' !== $completionsPath ? $completionsPath : '/chat/completions',
        ];
    }

    private function resolveSecret(string $value): string
    {
        if (str_starts_with($value, 'env:')) {
            $resolved = getenv(substr($value, 4));

            return false !== $resolved ? $resolved : '';
        }

        return $value;
    }

    /**
     * Pre-write session metadata YAML.
     *
     * @param array<string, string> $meta
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
}
