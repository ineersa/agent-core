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
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionAwareModelResolver;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface as SymfonyPlatformInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Yaml\Yaml;

/**
 * Opt-in real llama.cpp smoke test.
 *
 * Proves the configured generic provider can call a real llama.cpp
 * OpenAI chat-completions-style endpoint. Requires environment
 * configuration to enable (see docs).
 *
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
                'LLAMA_CPP_SMOKE_TEST is not set. '
                .'Set LLAMA_CPP_SMOKE_TEST=1 and configure LLAMA_CPP_BASE_URL '
                .'to run the real llama.cpp smoke test.'
            );
        }

        $baseUrl = getenv('LLAMA_CPP_BASE_URL');
        if (false === $baseUrl || '' === $baseUrl) {
            self::markTestSkipped(
                'LLAMA_CPP_BASE_URL is not set. '
                .'Configure LLAMA_CPP_BASE_URL (e.g. http://192.168.2.38:8052/v1) '
                .'when LLAMA_CPP_SMOKE_TEST is enabled.'
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
        $baseUrl = getenv('LLAMA_CPP_BASE_URL');
        $modelName = getenv('LLAMA_CPP_MODEL') ?: 'flash';
        $apiKey = false !== getenv('LLAMA_CPP_API_KEY') ? getenv('LLAMA_CPP_API_KEY') : 'dummy';
        $modelRef = 'llama_cpp/'.$modelName;

        // ── Session metadata: pre-set model and reasoning ──
        $this->writeSessionMetadata($this->sessionId, [
            'model' => $modelRef,
            'reasoning' => 'off',
        ]);

        // ── Real model resolution from session metadata ──
        $modelResolver = $this->createSessionAwareResolver($modelRef, $modelName);
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new ModelResolverRoutingSubscriber($modelResolver));

        // ── Build the real Platform with a live Provider ──
        $provider = GenericFactory::createProvider(
            baseUrl: $baseUrl,
            apiKey: $apiKey,
            httpClient: null,
            modelCatalog: new FallbackModelCatalog(),
            eventDispatcher: $eventDispatcher,
            supportsCompletions: true,
            supportsEmbeddings: false,
            name: 'llama_cpp',
        );

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
    private function createSessionAwareResolver(string $modelRef, string $modelName): SessionAwareModelResolver
    {
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $appConfig = $this->makeAppConfig($modelRef, $modelName);
        $selectionService = new ModelSelectionService($appConfig, $homeWriter, $this->sessionMetaStore);

        return new SessionAwareModelResolver($selectionService);
    }

    /**
     * Build an AppConfig with only the llama_cpp provider configured.
     *
     * @return array<string, mixed>
     */
    private function makeAppConfig(string $modelRef, string $modelName): AppConfig
    {
        $aiData = [
            'default_model' => $modelRef,
            'default_reasoning' => 'off',
            'providers' => [
                'llama_cpp' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => getenv('LLAMA_CPP_BASE_URL'),
                    'completions_path' => '/v1/chat/completions',
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
