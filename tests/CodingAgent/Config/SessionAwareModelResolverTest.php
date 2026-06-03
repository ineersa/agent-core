<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
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
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\AI\Platform\Message\MessageBag;

final class SessionAwareModelResolverTest extends KernelTestCase
{
    protected static function createKernel(array $options = []): \Ineersa\CodingAgent\Kernel
    {
        return new \Ineersa\CodingAgent\Kernel($options['environment'] ?? 'test', (bool) ($options['debug'] ?? false));
    }

    private string $tempDir;
    private string $homeDir;
    private \Doctrine\ORM\EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();
        
        self::bootKernel(['environment' => 'test', 'debug' => false]);
        $container = static::getContainer();
        $this->entityManager = $container->get('doctrine.orm.default_entity_manager');
        
        $this->tempDir = sys_get_temp_dir().'/hatfield-resolver-test-'.uniqid('', true);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir, 0777, true);
        mkdir($this->homeDir.'/.hatfield', 0777, true);
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        self::ensureKernelShutdown();
        parent::tearDown();
        // Pop the exception handler that FrameworkBundle::boot() registered
        // during kernel boot/shutdown. Parent tearDown calls
        // ensureKernelShutdown() which may re-boot and re-register, so
        // this must run after parent::tearDown().
        restore_exception_handler();
    }

    public function testResolveReturnsModelFromSessionMetadata(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $sessionId = $this->writeSessionMetadata('sess-1', ['model' => 'llama_cpp/flash']);

        $result = $resolver->resolve(
            'deepseek/deepseek-v4-pro',
            new MessageBag(),
            new ModelInvocationInput(runId: $sessionId),
            new ModelResolutionOptions(),
        );

        self::assertSame('llama_cpp/flash', $result->model);
        self::assertSame('llama_cpp', $result->providerId);
        self::assertSame('medium', $result->reasoning);
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

    public function testResolveFallsBackToDefaultWhenNoModelsConfigured(): void
    {
        $resolver = $this->createResolver([]);

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

    public function testResolveReturnsEmptyProviderIdWhenDefaultHasNoProviderPrefix(): void
    {
        $resolver = $this->createResolver([]);

        $result = $resolver->resolve(
            'just-a-model',
            new MessageBag(),
            new ModelInvocationInput(),
            new ModelResolutionOptions(),
        );

        self::assertSame('just-a-model', $result->model);
        self::assertSame('', $result->providerId);
        self::assertSame('medium', $result->reasoning);
    }

    public function testResolveReturnsReasoningFromSessionMetadata(): void
    {
        $resolver = $this->createResolver($this->standardAiData());
        $sessionId = $this->writeSessionMetadata('sess-2', ['model' => 'deepseek/deepseek-v4-pro', 'reasoning' => 'high']);

        $result = $resolver->resolve(
            'deepseek/deepseek-v4-pro',
            new MessageBag(),
            new ModelInvocationInput(runId: $sessionId),
            new ModelResolutionOptions(),
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
            lockFactory: new LockFactory(new FlockStore()),
            entityManager: $this->entityManager,
        );
        $sessionMetaStore = new SessionMetadataStore($hatfieldSessionStore);

        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $appConfig = $this->makeAppConfig($aiData);
        $selectionService = new ModelSelectionService($appConfig, new ModelResolver($appConfig, $sessionMetaStore), new ModelSettingsPersister($homeWriter, $sessionMetaStore));

        return new SessionAwareModelResolver($selectionService);
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
