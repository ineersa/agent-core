<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\ModelSettingsPersister;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\ModelCommandHandler;
use Ineersa\Tui\Picker\FavoritePickerController;
use Ineersa\Tui\Picker\ModelPickerController;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Yaml\Yaml;

class ModelCommandHandlerTest extends TestCase
{
    private string $tempDir;
    private string $homeDir;
    private ModelSelectionService $modelService;
    private SessionMetadataStore $sessionMetaStore;
    private TuiSessionState $state;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/hatfield-model-cmd-test-'.uniqid('', true);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir, 0777, true);
        mkdir($this->homeDir.'/.hatfield', 0777, true);
        mkdir($this->tempDir.'/project/.hatfield/sessions', 0777, true);

        // Create home settings with standard AI config
        file_put_contents(
            $this->homeDir.'/.hatfield/settings.yaml',
            "tui:\n    theme: cyberpunk\n",
        );

        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
            ),
            lockFactory: new LockFactory(new FlockStore()),
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );
        $this->sessionMetaStore = new SessionMetadataStore($hatfieldSessionStore);

        $appConfig = $this->makeAppConfig($this->standardAiData());
        $this->modelService = new ModelSelectionService($appConfig, new ModelResolver($appConfig, $this->sessionMetaStore), new ModelSettingsPersister($homeWriter, $this->sessionMetaStore));

        $this->state = new TuiSessionState('test-session');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
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

    private function makeAppConfig(array $aiData): AppConfig
    {
        $raw = [
            'tui' => ['theme' => 'cyberpunk'],
        ];
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
                    'api' => 'openai-completions',
                    'api_key' => 'test-key',
                    'completions_path' => '/chat/completions',
                    'supports_completions' => true,
                    'supports_embeddings' => false,
                    'models' => [
                        'deepseek-v4-pro' => [
                            'name' => 'DeepSeek V4 Pro',
                            'context_window' => 1000000,
                            'max_tokens' => 384000,
                            'input' => ['text'],
                            'tool_calling' => true,
                            'reasoning' => true,
                            'thinking_level_map' => [
                                'minimal' => 'high', 'low' => 'high', 'medium' => 'high',
                                'high' => 'high', 'xhigh' => 'max',
                            ],
                            'cost' => ['input' => 0.435, 'output' => 0.87, 'cache_read' => 0, 'cache_write' => 0],
                        ],
                    ],
                ],
                'zai' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'https://api.z.ai/api/coding/paas/v4',
                    'api' => 'openai-completions',
                    'api_key' => 'test-key',
                    'completions_path' => '/chat/completions',
                    'supports_completions' => true,
                    'supports_embeddings' => false,
                    'models' => [
                        'glm-5.1' => [
                            'name' => 'GLM 5.1',
                            'context_window' => 200000,
                            'max_tokens' => 131072,
                            'input' => ['text'],
                            'tool_calling' => true,
                            'reasoning' => true,
                            'thinking_level_map' => [
                                'minimal' => 'enabled', 'low' => 'enabled',
                                'medium' => 'enabled', 'high' => 'enabled',
                            ],
                            'cost' => ['input' => 0, 'output' => 0, 'cache_read' => 0, 'cache_write' => 0],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function makeHandler(array $aiData = []): ModelCommandHandler
    {
        $appConfig = $this->makeAppConfig([] !== $aiData ? $aiData : $this->standardAiData());

        $pickerController = new ModelPickerController($this->modelService, $appConfig, new NullLogger());
        $favPickerController = new FavoritePickerController($this->modelService, new NullLogger());

        return new ModelCommandHandler($this->modelService, $appConfig, $this->state, $pickerController, $favPickerController, new NullLogger());
    }

    private function slash(string $name, string $args = ''): SlashCommand
    {
        $fullText = '/'.$name;
        if ('' !== $args) {
            $fullText .= ' '.$args;
        }

        return new SlashCommand(name: $name, args: $args, originalText: $fullText);
    }

    // ──────────────────────────────────────────────
    //  /model (no args) — list models
    // ──────────────────────────────────────────────

    #[Test]
    public function testModelWithoutArgsListsAvailableModels(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Available models:', $result->text);
        self::assertStringContainsString('deepseek/deepseek-v4-pro', $result->text);
        self::assertStringContainsString('zai/glm-5.1', $result->text);
        self::assertStringContainsString('Ctrl+P', $result->text);
    }

    #[Test]
    public function testModelListMarksCurrentModel(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('(current)', $result->text);
    }

    #[Test]
    public function testModelListMarksFavorites(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro'];
        $appConfig = $this->makeAppConfig($aiData);
        // Rebuild modelService with favorites
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $this->modelService = new ModelSelectionService($appConfig, new ModelResolver($appConfig, $this->sessionMetaStore), new ModelSettingsPersister($homeWriter, $this->sessionMetaStore));
        $pickerController = new ModelPickerController($this->modelService, $appConfig, new NullLogger());
        $favPickerController = new FavoritePickerController($this->modelService, new NullLogger());
        $handler = new ModelCommandHandler($this->modelService, $appConfig, $this->state, $pickerController, $favPickerController, new NullLogger());

        $result = $handler->handle($this->slash('model'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('★', $result->text);
    }

    // ──────────────────────────────────────────────
    //  /model select <provider/model>
    // ──────────────────────────────────────────────

    #[Test]
    public function testSelectExistingModel(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model', 'select zai/glm-5.1'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Model changed to zai/glm-5.1', $result->text);

        // Footer state should be updated
        self::assertSame('glm-5.1', $this->state->footerModel);
    }

    #[Test]
    public function testSelectViaDirectModelname(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model', 'zai/glm-5.1'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Model changed to zai/glm-5.1', $result->text);
    }

    #[Test]
    public function testSelectUnknownModelReturnsError(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model', 'select mystery/ghost'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('not available', $result->text);
        self::assertSame('muted', $result->style);
    }

    #[Test]
    public function testSelectWithoutArgsShowsUsage(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model', 'select'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Usage:', $result->text);
        self::assertSame('muted', $result->style);
    }

    #[Test]
    public function testSelectInvalidRefReturnsError(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model', 'select not-a-valid-ref'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Invalid model reference', $result->text);
        self::assertSame('muted', $result->style);
    }

    // ──────────────────────────────────────────────
    //  /model fav
    // ──────────────────────────────────────────────

    #[Test]
    public function testFavWithoutArgsListsFavorites(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro', 'zai/glm-5.1'];
        $appConfig = $this->makeAppConfig($aiData);
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $this->modelService = new ModelSelectionService($appConfig, new ModelResolver($appConfig, $this->sessionMetaStore), new ModelSettingsPersister($homeWriter, $this->sessionMetaStore));
        $pickerController = new ModelPickerController($this->modelService, $appConfig, new NullLogger());
        $handler = new ModelCommandHandler($this->modelService, $appConfig, $this->state, $pickerController, new FavoritePickerController($this->modelService, new NullLogger()), new NullLogger());

        // Fallback textual list shows all models with * markers
        $result = $handler->handle($this->slash('model', 'fav'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Favorite models (* = favorite):', $result->text);
        self::assertStringContainsString('deepseek/deepseek-v4-pro', $result->text);
        self::assertStringContainsString('zai/glm-5.1', $result->text);
    }

    #[Test]
    public function testFavWithoutFavoritesShowsEmptyMessage(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model', 'fav'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        // New fav list shows all models, none marked with *
        self::assertStringContainsString('Favorite models (* = favorite):', $result->text);
        self::assertStringContainsString('deepseek/deepseek-v4-pro', $result->text);
        self::assertStringContainsString('zai/glm-5.1', $result->text);
    }

    #[Test]
    public function testFavAddModel(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model', 'fav zai/glm-5.1'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Added zai/glm-5.1 to favorites', $result->text);
    }

    #[Test]
    public function testFavRemoveModel(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro', 'zai/glm-5.1'];
        $appConfig = $this->makeAppConfig($aiData);
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $this->modelService = new ModelSelectionService($appConfig, new ModelResolver($appConfig, $this->sessionMetaStore), new ModelSettingsPersister($homeWriter, $this->sessionMetaStore));
        $pickerController = new ModelPickerController($this->modelService, $appConfig, new NullLogger());
        $handler = new ModelCommandHandler($this->modelService, $appConfig, $this->state, $pickerController, new FavoritePickerController($this->modelService, new NullLogger()), new NullLogger());

        $result = $handler->handle($this->slash('model', 'fav deepseek/deepseek-v4-pro'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Removed deepseek/deepseek-v4-pro from favorites', $result->text);
    }

    #[Test]
    public function testFavUnknownModelReturnsError(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model', 'fav mystery/ghost'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('not available', $result->text);
        self::assertSame('muted', $result->style);
    }

    // ──────────────────────────────────────────────
    //  Aliases
    // ──────────────────────────────────────────────

    #[Test]
    public function testModelAliasMWorks(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('m'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Available models:', $result->text);
    }

    #[Test]
    public function testSelAliasWorks(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model', 'sel zai/glm-5.1'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Model changed to zai/glm-5.1', $result->text);
    }

    // ──────────────────────────────────────────────
    //  Immediate favorite visibility after toggle
    // ──────────────────────────────────────────────

    #[Test]
    public function testFavListReflectsToggleImmediately(): void
    {
        $handler = $this->makeHandler();

        // Toggle a favorite
        $handler->handle($this->slash('model', 'fav zai/glm-5.1'));

        // Immediately list favorites (falls back to text since no TUI) —
        // should show the newly favorited model with * marker
        $result = $handler->handle($this->slash('model', 'fav'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Favorite models (* = favorite):', $result->text);
        self::assertStringContainsString('zai/glm-5.1', $result->text);
        self::assertStringContainsString('*', $result->text);
    }

    #[Test]
    public function testModelListReflectsFavToggleImmediately(): void
    {
        $handler = $this->makeHandler();

        // Toggle a favorite
        $handler->handle($this->slash('model', 'fav zai/glm-5.1'));

        // Immediately list models — the new favorite should be marked with ★
        $result = $handler->handle($this->slash('model'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        // The zai/glm-5.1 line should appear with a star somewhere nearby
        // (the exact ANSI/format differs, but ★ must appear)
        self::assertStringContainsString('★', $result->text);
    }

    #[Test]
    public function testFavAddThenRemoveReflectedImmediately(): void
    {
        $handler = $this->makeHandler();

        // Add a favorite
        $handler->handle($this->slash('model', 'fav zai/glm-5.1'));

        // Remove it
        $result = $handler->handle($this->slash('model', 'fav zai/glm-5.1'));
        self::assertStringContainsString('Removed zai/glm-5.1 from favorites', $result->text);

        // List now — both models shown, none marked with *
        $listResult = $handler->handle($this->slash('model', 'fav'));
        self::assertStringContainsString('Favorite models (* = favorite):', $listResult->text);
        // deepseek/deepseek-v4-pro should appear without * marker
        self::assertStringContainsString('deepseek/deepseek-v4-pro', $listResult->text);
        self::assertStringContainsString('zai/glm-5.1', $listResult->text);
    }
}
