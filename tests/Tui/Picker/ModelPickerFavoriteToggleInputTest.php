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
use Ineersa\Tui\Picker\ModelPickerController;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Tui\Widget\SelectListWidget;

/**
 * Test thesis: model-picker Ctrl+F favorite toggle matches through list
 * keybindings for legacy and Kitty forms and toggles the selected model.
 */
#[CoversClass(ModelPickerController::class)]
final class ModelPickerFavoriteToggleInputTest extends TestCase
{
    private string $projectDir;
    private string $homeDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('picker-fav');
        $this->homeDir = TestDirectoryIsolation::createOsTempDir('picker-fav-home');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: default\n");
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        TestDirectoryIsolation::removeDirectory($this->homeDir);
        parent::tearDown();
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
    public function openPickerOnInputConsumesCtrlF(string $sequence): void
    {
        $modelService = $this->buildService();
        $this->assertFalse($modelService->isFavorite(new AiModelReference('llama_cpp', 'flash')));

        $harness = new VirtualTuiHarness(sessionId: '1');
        $state = new TuiSessionState('1');
        $controller = new ModelPickerController(
            $harness->tui(),
            $harness->screen(),
            $state,
            $modelService,
            $this->makeAppConfig($this->standardAiData()),
            new NullLogger(),
        );
        $controller->open();

        $focus = $harness->tui()->getFocus();
        $this->assertInstanceOf(SelectListWidget::class, $focus);
        $focus->handleInput($sequence);

        $this->assertTrue($modelService->isFavorite(new AiModelReference('llama_cpp', 'flash')));
    }

    private function buildService(): ModelSelectionService
    {
        $appConfig = $this->makeAppConfig($this->standardAiData());
        $pathResolver = new SettingsPathResolver($this->projectDir, $this->homeDir);
        $homeWriter = new SettingsOverrideWriter($pathResolver, PropertyAccess::createPropertyAccessor(), new Filesystem());
        $sessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );

        return new ModelSelectionService($appConfig, new ModelResolver($appConfig, $sessionStore), $homeWriter, $sessionStore);
    }

    /**
     * @param array<string, mixed> $aiData
     */
    private function makeAppConfig(array $aiData): AppConfig
    {
        $raw = [
            'tui' => ['theme' => 'default'],
            'ai' => $aiData,
        ];
        $ai = AiConfig::optionalFromArray($raw);

        return new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            ai: $ai,
            raw: $raw,
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: $this->projectDir,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function standardAiData(): array
    {
        return [
            'default_model' => 'llama_cpp/flash',
            'favorite_models' => [],
            'providers' => [
                'llama_cpp' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'http://127.0.0.1:9052/v1',
                    'models' => [
                        'flash' => [
                            'id' => 'flash',
                            'name' => 'Flash',
                            'context_window' => 200000,
                            'max_tokens' => 65536,
                            'input' => ['text'],
                            'reasoning' => false,
                        ],
                        'test' => [
                            'id' => 'test',
                            'name' => 'Test',
                            'context_window' => 8192,
                            'max_tokens' => 1024,
                            'input' => ['text'],
                            'reasoning' => false,
                        ],
                    ],
                ],
            ],
        ];
    }
}
