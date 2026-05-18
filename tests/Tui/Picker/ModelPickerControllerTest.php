<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\Tui\Picker\ModelPickerController;
use Ineersa\Tui\Runtime\TuiSessionState;
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

        $items = ModelPickerController::buildItemsStatic($service, $state);

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

        $items = ModelPickerController::buildItemsStatic($service, $state);

        // Current model (default) should have ❯ marker
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

    private function buildService(array $aiOverrides = []): ModelSelectionService
    {
        $aiData = $this->standardAiData();
        foreach ($aiOverrides as $key => $value) {
            $aiData[$key] = $value;
        }

        $ai = AiConfig::optionalFromArray(['ai' => $aiData]);
        $appConfig = new AppConfig(
            tui: TuiConfig::fromArray(['theme' => 'cyberpunk']),
            sessions: [],
            ai: $ai,
            raw: ['ai' => $aiData],
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: getcwd() ?: '/',
        );

        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $sessionMetaStore = new SessionMetadataStore();
        $sessionMetaStore->setSessionsBasePath($this->tempDir.'/project/.hatfield/sessions');

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
