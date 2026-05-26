<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Ineersa\Tui\Picker\FavoritePickerController;
use Ineersa\Tui\Picker\ModelPickerController;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Theme\TuiTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the static model picker item builder and findItemIndex.
 *
 * Does not require a running TUI or Symfony widget tree — the tested
 * methods are pure data transforms.
 */
class ModelPickerControllerTest extends TestCase
{
    private string $tempDir;
    private string $homeDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/hatfield-picker-test-'.uniqid('', true);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir, 0777, true);
        mkdir($this->homeDir.'/.hatfield', 0777, true);
        mkdir($this->tempDir.'/project/.hatfield/sessions', 0777, true);
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
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
        self::assertGreaterThanOrEqual(2, count($items));

        // First item should be the favorite
        self::assertStringContainsString('llama_cpp/flash', $items[0]['label']);

        // Favorite should have ★ marker
        self::assertStringContainsString('★', $items[0]['label']);
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
        self::assertTrue($currentFound, 'Current model should be marked with ❯');
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
            self::assertArrayNotHasKey('description', $item);
        }
    }

    // ── Favorite picker item builder ──

    #[Test]
    public function testBuildFavoritesItemsMarksFavoritesWithAsterisk(): void
    {
        $service = $this->buildService(['favorite_models' => ['llama_cpp/flash']]);

        $items = FavoritePickerController::buildFavoritesItems($service, $this->createTheme());

        self::assertGreaterThanOrEqual(2, count($items));

        $favFound = false;
        foreach ($items as $item) {
            if ('llama_cpp/flash' === $item['value']) {
                $favFound = true;
                self::assertStringContainsString('*', $item['label']);
            }
        }
        self::assertTrue($favFound, 'Favorited model should be in items');
    }

    #[Test]
    public function testBuildFavoritesItemsNonFavoriteHasNoMarker(): void
    {
        $service = $this->buildService();

        $items = FavoritePickerController::buildFavoritesItems($service, $this->createTheme());

        self::assertGreaterThanOrEqual(2, count($items));

        foreach ($items as $item) {
            $label = $item['label'];
            // Label starts with space or * marker followed by model name.
            // Non-favorites should start with a space, not with *.
            // Do NOT trim — leading space IS the marker for non-favorites.
            $firstChar = mb_substr($label, 0, 1);
            self::assertNotSame('*', $firstChar, 'Non-favorite items should not have * marker');
            self::assertSame(' ', $firstChar, 'Non-favorite items should have space as marker placeholder');
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

        self::assertSame(0, ModelPickerController::findItemIndex($items, 'model-a'));
        self::assertSame(1, ModelPickerController::findItemIndex($items, 'model-b'));
        self::assertSame(2, ModelPickerController::findItemIndex($items, 'model-c'));
        self::assertNull(ModelPickerController::findItemIndex($items, 'model-d'));
    }

    #[Test]
    public function testFindItemIndexReturnsNullForEmptyArray(): void
    {
        self::assertNull(ModelPickerController::findItemIndex([], 'anything'));
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
        $appConfig = new AppConfig(
            tui: TuiConfig::fromArray(['theme' => 'cyberpunk']),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            ai: $ai,
            raw: ['ai' => $aiData],
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: getcwd() ?: '/',
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
        );
        $sessionMetaStore = new SessionMetadataStore($hatfieldSessionStore);

        return new ModelSelectionService($appConfig, $homeWriter, $sessionMetaStore);
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
