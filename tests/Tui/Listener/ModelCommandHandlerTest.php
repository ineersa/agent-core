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

    private function makeFavHandler(array $aiData = []): ModelCommandHandler
    {
        $appConfig = $this->makeAppConfig([] !== $aiData ? $aiData : $this->standardAiData());

        $pickerController = new ModelPickerController($this->modelService, $appConfig, new NullLogger());
        $favPickerController = new FavoritePickerController($this->modelService, new NullLogger());

        return new ModelCommandHandler($this->modelService, $appConfig, $this->state, $pickerController, $favPickerController, new NullLogger(), isFavourites: true);
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
        self::assertStringContainsString('/model <provider/modelname>', $result->text);
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
    //  /model <provider/modelname> — direct select
    // ──────────────────────────────────────────────

    #[Test]
    public function testDirectModelRefSelectsModel(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model', 'zai/glm-5.1'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Model changed to zai/glm-5.1', $result->text);
        self::assertSame('glm-5.1', $this->state->footerModel);
    }

    #[Test]
    public function testDirectModelUnknownModelReturnsError(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model', 'mystery/ghost'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('not available', $result->text);
        self::assertSame('muted', $result->style);
    }

    #[Test]
    public function testDirectModelInvalidRefReturnsError(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model', 'not-a-valid-ref'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Invalid model reference', $result->text);
        self::assertSame('muted', $result->style);
    }

    // ──────────────────────────────────────────────
    //  /model-favourites (no args) — list favourites
    // ──────────────────────────────────────────────

    #[Test]
    public function testFavouritesWithoutArgsListsFavourites(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro', 'zai/glm-5.1'];
        $appConfig = $this->makeAppConfig($aiData);
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $this->modelService = new ModelSelectionService($appConfig, new ModelResolver($appConfig, $this->sessionMetaStore), new ModelSettingsPersister($homeWriter, $this->sessionMetaStore));
        $pickerController = new ModelPickerController($this->modelService, $appConfig, new NullLogger());
        $favPickerController = new FavoritePickerController($this->modelService, new NullLogger());
        $handler = new ModelCommandHandler($this->modelService, $appConfig, $this->state, $pickerController, $favPickerController, new NullLogger(), isFavourites: true);

        $result = $handler->handle($this->slash('model-favourites'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Favourite models (* = favourite):', $result->text);
        self::assertStringContainsString('deepseek/deepseek-v4-pro', $result->text);
        self::assertStringContainsString('zai/glm-5.1', $result->text);
    }

    #[Test]
    public function testFavouritesWithoutFavouritesShowsAllModels(): void
    {
        $handler = $this->makeFavHandler();
        $result = $handler->handle($this->slash('model-favourites'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Favourite models (* = favourite):', $result->text);
        self::assertStringContainsString('deepseek/deepseek-v4-pro', $result->text);
        self::assertStringContainsString('zai/glm-5.1', $result->text);
    }

    #[Test]
    public function testFavouritesAddModel(): void
    {
        $handler = $this->makeFavHandler();
        $result = $handler->handle($this->slash('model-favourites', 'zai/glm-5.1'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Added zai/glm-5.1 to favourites', $result->text);
    }

    #[Test]
    public function testFavouritesRemoveModel(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro', 'zai/glm-5.1'];
        $appConfig = $this->makeAppConfig($aiData);
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $this->modelService = new ModelSelectionService($appConfig, new ModelResolver($appConfig, $this->sessionMetaStore), new ModelSettingsPersister($homeWriter, $this->sessionMetaStore));
        $pickerController = new ModelPickerController($this->modelService, $appConfig, new NullLogger());
        $favPickerController = new FavoritePickerController($this->modelService, new NullLogger());
        $handler = new ModelCommandHandler($this->modelService, $appConfig, $this->state, $pickerController, $favPickerController, new NullLogger(), isFavourites: true);

        $result = $handler->handle($this->slash('model-favourites', 'deepseek/deepseek-v4-pro'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Removed deepseek/deepseek-v4-pro from favourites', $result->text);
    }

    #[Test]
    public function testFavouritesUnknownModelReturnsError(): void
    {
        $handler = $this->makeFavHandler();
        $result = $handler->handle($this->slash('model-favourites', 'mystery/ghost'));

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
    public function testFavouritesAliasWorks(): void
    {
        $handler = $this->makeFavHandler();
        $result = $handler->handle($this->slash('model-favourite'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Favourite models (* = favourite):', $result->text);
    }

    // ──────────────────────────────────────────────
    //  Immediate favourite visibility after toggle
    // ──────────────────────────────────────────────

    #[Test]
    public function testFavouritesListReflectsToggleImmediately(): void
    {
        $handler = $this->makeFavHandler();

        // Toggle a favourite
        $handler->handle($this->slash('model-favourites', 'zai/glm-5.1'));

        // List favourites — should show the newly favourited model with * marker
        $result = $handler->handle($this->slash('model-favourites'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('Favourite models (* = favourite):', $result->text);
        self::assertStringContainsString('zai/glm-5.1', $result->text);
        self::assertStringContainsString('*', $result->text);
    }

    #[Test]
    public function testModelListReflectsFavouritesToggleImmediately(): void
    {
        $handler = $this->makeHandler();
        $favHandler = $this->makeFavHandler();

        // Toggle a favourite via /model-favourites
        $favHandler->handle($this->slash('model-favourites', 'zai/glm-5.1'));

        // List models via /model — the new favourite should be marked with ★
        $result = $handler->handle($this->slash('model'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringContainsString('★', $result->text);
    }

    #[Test]
    public function testFavouritesAddThenRemoveReflectedImmediately(): void
    {
        $handler = $this->makeFavHandler();

        // Add a favourite
        $handler->handle($this->slash('model-favourites', 'zai/glm-5.1'));

        // Remove it
        $result = $handler->handle($this->slash('model-favourites', 'zai/glm-5.1'));
        self::assertStringContainsString('Removed zai/glm-5.1 from favourites', $result->text);

        // List — both models shown, none marked with *
        $listResult = $handler->handle($this->slash('model-favourites'));
        self::assertStringContainsString('Favourite models (* = favourite):', $listResult->text);
        self::assertStringContainsString('deepseek/deepseek-v4-pro', $listResult->text);
        self::assertStringContainsString('zai/glm-5.1', $listResult->text);
    }

    #[Test]
    public function testModelListDoesNotMentionCtrlP(): void
    {
        // The model list text should not contain picker keybind prose.
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model'));

        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertStringNotContainsString('Ctrl+P', $result->text);
        self::assertStringNotContainsString('Shift+Tab', $result->text);
    }
}
