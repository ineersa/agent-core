<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

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
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Picker\FavoritePickerController;
use Ineersa\Tui\Picker\ModelPickerController;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Theme\TuiTheme;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Tui\Widget\SelectListWidget;

/**
 * Tests model picker item construction and input routing.
 */
class ModelPickerControllerTest extends TestCase
{
    private string $tempDir;
    private string $homeDir;
    private AppConfig $appConfig;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('picker');
        $this->homeDir = TestDirectoryIsolation::createOsTempDir('picker-home');
        TestDirectoryIsolation::createHatfieldTree($this->tempDir, withSessions: true);
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tempDir);
        TestDirectoryIsolation::removeDirectory($this->homeDir);
        parent::tearDown();
    }

    #[Test]
    public function testBuildItemsStaticReturnsFavoritesFirst(): void
    {
        $service = $this->buildService([
            'favorite_models' => ['llama_cpp/flash'],
        ]);
        $state = new TuiSessionState('test');

        $items = ModelPickerController::buildItemsStatic($service, $state, $this->createTheme());

        // At least 2 models configured
        $this->assertGreaterThanOrEqual(2, \count($items));

        // First item should be the favorite
        $this->assertStringContainsString('llama_cpp/flash', $items[0]['label']);

        // Favorite should have ★ marker
        $this->assertStringContainsString('★', $items[0]['label']);
    }

    #[Test]
    public function testBuildItemsStaticMarksCurrentModel(): void
    {
        $service = $this->buildService();
        $state = new TuiSessionState('test');

        $items = ModelPickerController::buildItemsStatic($service, $state, $this->createTheme());

        // Current model (default) should have ❯ marker (visual only, no description)
        $currentFound = false;
        foreach ($items as $item) {
            if (str_contains($item['label'], '❯')) {
                $currentFound = true;
                break;
            }
        }
        $this->assertTrue($currentFound, 'Current model should be marked with ❯');
    }

    #[Test]
    public function testNoItemsHaveDescription(): void
    {
        $service = $this->buildService();
        $state = new TuiSessionState('test');

        $items = ModelPickerController::buildItemsStatic($service, $state, $this->createTheme());

        // No item should carry a description key — visual distinction is
        // handled by coloured markers, not textual metadata.
        foreach ($items as $item) {
            $this->assertArrayNotHasKey('description', $item);
        }
    }

    // ── Favorite picker item builder ──

    #[Test]
    public function testBuildFavoritesItemsMarksFavoritesWithAsterisk(): void
    {
        $service = $this->buildService(['favorite_models' => ['llama_cpp/flash']]);

        $items = FavoritePickerController::buildFavoritesItems($service, $this->createTheme());

        $this->assertGreaterThanOrEqual(2, \count($items));

        $favFound = false;
        foreach ($items as $item) {
            if ('llama_cpp/flash' === $item['value']) {
                $favFound = true;
                $this->assertStringContainsString('*', $item['label']);
            }
        }
        $this->assertTrue($favFound, 'Favorited model should be in items');
    }

    #[Test]
    public function testBuildFavoritesItemsNonFavoriteHasNoMarker(): void
    {
        $service = $this->buildService();

        $items = FavoritePickerController::buildFavoritesItems($service, $this->createTheme());

        $this->assertGreaterThanOrEqual(2, \count($items));

        foreach ($items as $item) {
            $label = $item['label'];
            // Label starts with space or * marker followed by model name.
            // Non-favorites should start with a space, not with *.
            // Do NOT trim — leading space IS the marker for non-favorites.
            $firstChar = mb_substr($label, 0, 1);
            $this->assertNotSame('*', $firstChar, 'Non-favorite items should not have * marker');
            $this->assertSame(' ', $firstChar, 'Non-favorite items should have space as marker placeholder');
        }
    }

    #[Test]
    public function testFindItemIndexFindsCorrectPosition(): void
    {
        $items = [
            ['value' => 'model-a', 'label' => 'Model A'],
            ['value' => 'model-b', 'label' => 'Model B'],
            ['value' => 'model-c', 'label' => 'Model C'],
        ];

        $this->assertSame(0, ModelPickerController::findItemIndex($items, 'model-a'));
        $this->assertSame(1, ModelPickerController::findItemIndex($items, 'model-b'));
        $this->assertSame(2, ModelPickerController::findItemIndex($items, 'model-c'));
        $this->assertNull(ModelPickerController::findItemIndex($items, 'model-d'));
    }

    #[Test]
    public function testFindItemIndexReturnsNullForEmptyArray(): void
    {
        $this->assertNull(ModelPickerController::findItemIndex([], 'anything'));
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideCtrlFSequences(): iterable
    {
        yield 'legacy' => ["\x06"];
        yield 'kitty' => ["\x1b[102;5u"];
    }

    #[Test]
    #[DataProvider('provideCtrlFSequences')]
    public function ctrlFTogglesTheSelectedFavorite(string $sequence): void
    {
        $modelService = $this->buildService();
        $model = new AiModelReference('deepseek', 'deepseek-v4-pro');
        $this->assertFalse($modelService->isFavorite($model));

        $harness = new VirtualTuiHarness(sessionId: 'picker');
        $controller = new ModelPickerController(
            $harness->tui(),
            $harness->screen(),
            new TuiSessionState('picker'),
            $modelService,
            $this->appConfig,
            new NullLogger(),
        );
        $controller->open();

        $focus = $harness->tui()->getFocus();
        $this->assertInstanceOf(SelectListWidget::class, $focus);
        $focus->handleInput($sequence);

        $this->assertTrue($modelService->isFavorite($model));
    }

    // ── Helpers ──

    /**
     * Create a test TuiTheme with an empty palette (plain-text markers).
     */
    private function createTheme(): TuiTheme
    {
        return new DefaultTheme(new ThemePalette(name: 'test', colors: []));
    }

    private function buildService(array $aiOverrides = []): ModelSelectionService
    {
        $aiData = $this->standardAiData();
        foreach ($aiOverrides as $key => $value) {
            $aiData[$key] = $value;
        }

        $ai = AiConfig::optionalFromArray(['ai' => $aiData]);
        $this->appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            ai: $ai,
            raw: ['ai' => $aiData],
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: getcwd() ?: '/',
        );

        $appConfig = $this->appConfig;
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new SettingsOverrideWriter($pathResolver, PropertyAccess::createPropertyAccessor(), new Filesystem());
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir,
            ),
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
        $sessionMetaStore = $hatfieldSessionStore;

        return new ModelSelectionService($appConfig, new ModelResolver($appConfig, $sessionMetaStore), $homeWriter, $sessionMetaStore);
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
                            'thinking_level_map' => ['minimal' => 'minimal', 'low' => 'low', 'medium' => 'medium', 'high' => 'high', 'xhigh' => 'max'],
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
                            'input' => ['text'],
                            'reasoning' => false,
                        ],
                    ],
                ],
            ],
        ];
    }
}
