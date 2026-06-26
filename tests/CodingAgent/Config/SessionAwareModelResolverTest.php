<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\ModelSettingsPersister;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\SessionAwareModelResolver;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\AI\Platform\Message\MessageBag;

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

        self::assertSame('llama_cpp/flash', $result->model);
        self::assertSame('llama_cpp', $result->providerId);
        self::assertSame('medium', $result->reasoning);
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

        self::assertSame('deepseek/deepseek-v4-pro', $result->model);
        self::assertSame('deepseek', $result->providerId);
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

        self::assertSame('deepseek/deepseek-v4-pro', $result->model);
        self::assertSame('deepseek', $result->providerId);
        self::assertSame('medium', $result->reasoning);
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

        self::assertNotEmpty($result->model);
        self::assertNotEmpty($result->providerId);
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
        self::assertSame('llama_cpp/flash', $result->model);
        self::assertSame('llama_cpp', $result->providerId);
        self::assertSame('medium', $result->reasoning);
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

        self::assertSame('high', $result->reasoning);
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

        self::assertSame('low', $result->reasoning);
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

        self::assertSame('high', $result->reasoning);
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

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
        $sessionMetaStore = new SessionMetadataStore($hatfieldSessionStore);

        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $appConfig = $this->makeAppConfig($aiData);
        $selectionService = new ModelSelectionService($appConfig, new ModelResolver($appConfig, $sessionMetaStore), new ModelSettingsPersister($homeWriter, $sessionMetaStore));

        $catalog = $appConfig->catalog ?? new HatfieldModelCatalog(new AiConfig(defaultModel: '', defaultReasoning: 'medium', providers: []));

        return new SessionAwareModelResolver($selectionService, $catalog);
    }

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
