<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

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
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Session metadata remains the resume-time authority for model and reasoning selection.
 */
final class TraceReplayTest extends IsolatedKernelTestCase
{
    private string $settingsDirectory;
    private HatfieldSessionStore $sessionMetaStore;

    private \Doctrine\ORM\EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        // Settings writes must not survive into the next method or target the actual HOME.
        $this->settingsDirectory = TestDirectoryIsolation::createProjectTempDir('trace-replay-settings');
        TestDirectoryIsolation::createHatfieldTree($this->settingsDirectory);
        TestDirectoryIsolation::createHatfieldTree($this->settingsDirectory.'/home');
        $this->sessionMetaStore = new HatfieldSessionStore(
            appConfig: new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig(), cwd: $this->isolatedCwd()),
            entityManager: $this->entityManager,
            dispatcher: new EventDispatcher(), lockFactory: new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore()));
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->settingsDirectory)) {
                TestDirectoryIsolation::removeDirectory($this->settingsDirectory);
            }
        } finally {
            parent::tearDown();
        }
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

    private function createSelectionService(array $aiData): ModelSelectionService
    {
        $config = $this->makeAppConfig($aiData);
        $writer = new SettingsOverrideWriter(new SettingsPathResolver($this->settingsDirectory, $this->settingsDirectory.'/home'), PropertyAccess::createPropertyAccessor(), new Filesystem());

        return new ModelSelectionService($config, new ModelResolver($config, $this->sessionMetaStore, new NullLogger()), $writer, $this->sessionMetaStore);
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
