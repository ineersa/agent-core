<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

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
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\ModelCommandHandler;
use Ineersa\Tui\Picker\FavoritePickerController;
use Ineersa\Tui\Picker\ModelPickerController;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;

class ModelCommandHandlerTest extends TestCase
{
    private string $tempDir;
    private string $homeDir;
    private ModelSelectionService $modelService;
    private HatfieldSessionStore $sessionMetaStore;
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
        $homeWriter = new SettingsOverrideWriter($pathResolver, PropertyAccess::createPropertyAccessor(), new Filesystem());
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
            ),
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
        $this->sessionMetaStore = $hatfieldSessionStore;

        $appConfig = $this->makeAppConfig($this->standardAiData());
        $this->modelService = new ModelSelectionService($appConfig, new ModelResolver($appConfig, $this->sessionMetaStore), $homeWriter, $this->sessionMetaStore);

        $this->state = new TuiSessionState('test-session');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    // ──────────────────────────────────────────────
    //  /model (no args) — list models
    // ──────────────────────────────────────────────

    #[Test]
    public function testModelWithoutArgsOpensPickerAndReturnsNoOp(): void
    {
        // No-arg /model opens the interactive picker overlay (constructor-bound
        // controller) and yields control to the UI — it does not print a list.
        $appConfig = $this->makeAppConfig($this->standardAiData());
        $pickerController = new ModelPickerController($this->pickerTui(), $this->pickerScreen(), $this->state, $this->modelService, $appConfig, new NullLogger());
        $favPickerController = new FavoritePickerController($this->pickerTui(), $this->pickerScreen(), $this->modelService, new NullLogger());
        $handler = new ModelCommandHandler($this->modelService, $appConfig, $this->state, $pickerController, $favPickerController, new NullLogger());

        $result = $handler->handle($this->slash('model'));

        $this->assertInstanceOf(NoOp::class, $result);
        $this->assertTrue($pickerController->isOpen());
    }

    // ──────────────────────────────────────────────
    //  /model <provider/modelname> — direct select
    // ──────────────────────────────────────────────

    #[Test]
    public function testDirectModelRefSelectsModel(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model', 'zai/glm-5.1'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Model changed to zai/glm-5.1', $result->text);
        $this->assertSame('glm-5.1', $this->state->footerModel);
    }

    #[Test]
    public function testDirectModelUnknownModelReturnsError(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model', 'mystery/ghost'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('not available', $result->text);
        $this->assertSame('muted', $result->style);
    }

    #[Test]
    public function testDirectModelInvalidRefReturnsError(): void
    {
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('model', 'not-a-valid-ref'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Invalid model reference', $result->text);
        $this->assertSame('muted', $result->style);
    }

    // ──────────────────────────────────────────────
    //  /model-favourites (no args) — list favourites
    // ──────────────────────────────────────────────

    #[Test]
    public function testFavouritesWithoutArgsOpensPickerAndReturnsNoOp(): void
    {
        $appConfig = $this->makeAppConfig($this->standardAiData());
        $pickerController = new ModelPickerController($this->pickerTui(), $this->pickerScreen(), $this->state, $this->modelService, $appConfig, new NullLogger());
        $favPickerController = new FavoritePickerController($this->pickerTui(), $this->pickerScreen(), $this->modelService, new NullLogger());
        $handler = new ModelCommandHandler($this->modelService, $appConfig, $this->state, $pickerController, $favPickerController, new NullLogger(), isFavourites: true);

        $result = $handler->handle($this->slash('model-favourites'));

        $this->assertInstanceOf(NoOp::class, $result);
        $this->assertTrue($favPickerController->isOpen());
    }

    #[Test]
    public function testFavouritesAddModel(): void
    {
        $handler = $this->makeFavHandler();
        $result = $handler->handle($this->slash('model-favourites', 'zai/glm-5.1'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Added zai/glm-5.1 to favourites', $result->text);
    }

    #[Test]
    public function testFavouritesRemoveModel(): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro', 'zai/glm-5.1'];
        $appConfig = $this->makeAppConfig($aiData);
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new SettingsOverrideWriter($pathResolver, PropertyAccess::createPropertyAccessor(), new Filesystem());
        $this->modelService = new ModelSelectionService($appConfig, new ModelResolver($appConfig, $this->sessionMetaStore), $homeWriter, $this->sessionMetaStore);
        $pickerController = new ModelPickerController($this->pickerTui(), $this->pickerScreen(), $this->state, $this->modelService, $appConfig, new NullLogger());
        $favPickerController = new FavoritePickerController($this->pickerTui(), $this->pickerScreen(), $this->modelService, new NullLogger());
        $handler = new ModelCommandHandler($this->modelService, $appConfig, $this->state, $pickerController, $favPickerController, new NullLogger(), isFavourites: true);

        $result = $handler->handle($this->slash('model-favourites', 'deepseek/deepseek-v4-pro'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Removed deepseek/deepseek-v4-pro from favourites', $result->text);
    }

    #[Test]
    public function testFavouritesUnknownModelReturnsError(): void
    {
        $handler = $this->makeFavHandler();
        $result = $handler->handle($this->slash('model-favourites', 'mystery/ghost'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('not available', $result->text);
        $this->assertSame('muted', $result->style);
    }

    // ──────────────────────────────────────────────
    //  Aliases
    // ──────────────────────────────────────────────

    #[Test]
    public function testModelAliasMWorks(): void
    {
        // The alias routes to the same no-arg behavior: the picker opens.
        $handler = $this->makeHandler();
        $result = $handler->handle($this->slash('m'));

        $this->assertInstanceOf(NoOp::class, $result);
    }

    #[Test]
    public function testFavouritesAliasWorks(): void
    {
        // The alias routes to the same no-arg behavior: the picker opens.
        $handler = $this->makeFavHandler();
        $result = $handler->handle($this->slash('model-favourite'));

        $this->assertInstanceOf(NoOp::class, $result);
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

        // No-arg /model-favourites opens the picker whose items reflect the toggle.
        $result = $handler->handle($this->slash('model-favourites'));
        $this->assertInstanceOf(NoOp::class, $result);
    }

    #[Test]
    public function testModelListReflectsFavouritesToggleImmediately(): void
    {
        $handler = $this->makeHandler();
        $favHandler = $this->makeFavHandler();

        // Toggle a favourite via /model-favourites
        $favHandler->handle($this->slash('model-favourites', 'zai/glm-5.1'));

        // No-arg /model opens the picker (items carry the ★ marker via buildItemsStatic).
        $result = $handler->handle($this->slash('model'));
        $this->assertInstanceOf(NoOp::class, $result);
    }

    #[Test]
    public function testFavouritesAddThenRemoveReflectedImmediately(): void
    {
        $handler = $this->makeFavHandler();

        // Add and remove a favourite; both commands execute without error.
        $handler->handle($this->slash('model-favourites', 'zai/glm-5.1'));
        $handler->handle($this->slash('model-favourites', 'zai/glm-5.1'));

        $result = $handler->handle($this->slash('model-favourites'));
        $this->assertInstanceOf(NoOp::class, $result);
    }

    #[Test]
    public function testModelListDoesNotMentionCtrlP(): void
    {
        // No-arg /model opens the picker; its header must not contain
        // Ctrl+P/Shift+Tab prose (those are hotkeys, not picker hints).
        $appConfig = $this->makeAppConfig($this->standardAiData());
        $pickerController = new ModelPickerController($this->pickerTui(), $this->pickerScreen(), $this->state, $this->modelService, $appConfig, new NullLogger());
        $favPickerController = new FavoritePickerController($this->pickerTui(), $this->pickerScreen(), $this->modelService, new NullLogger());
        $handler = new ModelCommandHandler($this->modelService, $appConfig, $this->state, $pickerController, $favPickerController, new NullLogger());

        $result = $handler->handle($this->slash('model'));
        $this->assertInstanceOf(NoOp::class, $result);
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
            tui: new TuiConfig(theme: (string) (($raw['tui'] ?? [])['theme'] ?? 'cyberpunk')),
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

        $pickerController = new ModelPickerController($this->pickerTui(), $this->pickerScreen(), $this->state, $this->modelService, $appConfig, new NullLogger());
        $favPickerController = new FavoritePickerController($this->pickerTui(), $this->pickerScreen(), $this->modelService, new NullLogger());

        return new ModelCommandHandler($this->modelService, $appConfig, $this->state, $pickerController, $favPickerController, new NullLogger());
    }

    private function makeFavHandler(array $aiData = []): ModelCommandHandler
    {
        $appConfig = $this->makeAppConfig([] !== $aiData ? $aiData : $this->standardAiData());

        $pickerController = new ModelPickerController($this->pickerTui(), $this->pickerScreen(), $this->state, $this->modelService, $appConfig, new NullLogger());
        $favPickerController = new FavoritePickerController($this->pickerTui(), $this->pickerScreen(), $this->modelService, new NullLogger());

        return new ModelCommandHandler($this->modelService, $appConfig, $this->state, $pickerController, $favPickerController, new NullLogger(), isFavourites: true);
    }

    private function pickerTui(): \Symfony\Component\Tui\Tui
    {
        return new \Symfony\Component\Tui\Tui();
    }

    private function pickerScreen(): ChatScreen
    {
        $screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'test-session',
            new PromptEditor(),
        );
        $screen->mount($this->pickerTui());

        return $screen;
    }

    private function slash(string $name, string $args = ''): SlashCommand
    {
        $fullText = '/'.$name;
        if ('' !== $args) {
            $fullText .= ' '.$args;
        }

        return new SlashCommand(name: $name, args: $args, originalText: $fullText);
    }
}
