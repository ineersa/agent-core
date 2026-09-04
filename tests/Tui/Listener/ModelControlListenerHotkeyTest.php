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
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Listener\ModelControlListener;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Test thesis: Ctrl+P and Shift+Tab hotkeys accept legacy and Kitty forms
 * through the mounted editor keybindings and invoke model-control actions.
 */
#[CoversClass(ModelControlListener::class)]
final class ModelControlListenerHotkeyTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private string $tempDir;
    private string $homeDir;
    private HatfieldSessionStore $sessionStore;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/hatfield-model-hotkey-'.uniqid('', true);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir.'/.hatfield', 0777, true);
        mkdir($this->tempDir.'/project/.hatfield/sessions', 0777, true);
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: default\n");

        $this->sessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
            ),
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
    }

    protected function tearDown(): void
    {
        $fs = new Filesystem();
        if (is_dir($this->tempDir)) {
            $fs->remove($this->tempDir);
        }
        parent::tearDown();
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideCtrlPSequences(): iterable
    {
        yield 'legacy' => ["\x10"];
        yield 'kitty' => ["\x1b[112;5u"];
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideShiftTabSequences(): iterable
    {
        yield 'legacy' => ["\x1b[Z"];
        yield 'kitty' => ["\x1b[9;2u"];
    }

    #[Test]
    #[DataProvider('provideCtrlPSequences')]
    public function ctrlPCyclesFavoriteModel(string $sequence): void
    {
        $aiData = $this->standardAiData();
        $aiData['favorite_models'] = ['deepseek/deepseek-v4-pro', 'llama_cpp/flash'];
        $appConfig = $this->makeAppConfig($aiData);
        $modelService = $this->buildService($aiData);

        $harness = new VirtualTuiHarness(sessionId: '1');
        $state = new TuiSessionState('');
        $state->footerModel = 'deepseek-v4-pro';

        $catalog = new SlashCommandCatalog();
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->withSessionServices($this->createSessionServices(
                tui: $harness->tui(),
                state: $state,
                screen: $harness->screen(),
                catalog: $catalog,
            ))
            ->build();

        $listener = new ModelControlListener($modelService, $appConfig, new NullLogger());
        $listener->registerCatalog($catalog);
        $listener->register($context);

        $harness->startInputLoop();
        $harness->sendInput($sequence);

        $this->assertSame('flash', $state->footerModel);
        $harness->stopInputLoop();
    }

    #[Test]
    #[DataProvider('provideShiftTabSequences')]
    public function shiftTabCyclesReasoning(string $sequence): void
    {
        $aiData = $this->standardAiData();
        $appConfig = $this->makeAppConfig($aiData);
        $modelService = $this->buildService($aiData);

        $harness = new VirtualTuiHarness(sessionId: '1');
        $state = new TuiSessionState('');
        $state->footerReasoning = 'medium';

        $catalog = new SlashCommandCatalog();
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->withSessionServices($this->createSessionServices(
                tui: $harness->tui(),
                state: $state,
                screen: $harness->screen(),
                catalog: $catalog,
            ))
            ->build();

        $listener = new ModelControlListener($modelService, $appConfig, new NullLogger());
        $listener->registerCatalog($catalog);
        $listener->register($context);

        $harness->startInputLoop();
        $harness->sendInput($sequence);

        $this->assertSame('high', $state->footerReasoning);
        $harness->stopInputLoop();
    }

    /**
     * @param array<string, mixed> $aiData
     */
    private function buildService(array $aiData): ModelSelectionService
    {
        $appConfig = $this->makeAppConfig($aiData);
        $pathResolver = new SettingsPathResolver($this->tempDir.'/project', $this->homeDir);
        $homeWriter = new SettingsOverrideWriter($pathResolver, PropertyAccess::createPropertyAccessor(), new Filesystem());

        return new ModelSelectionService($appConfig, new ModelResolver($appConfig, $this->sessionStore), $homeWriter, $this->sessionStore);
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
            cwd: $this->tempDir.'/project',
        );
    }

    /**
     * @return array<string, mixed>
     */
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
                            'thinking_level_map' => [
                                'minimal' => 'minimal',
                                'low' => 'low',
                                'medium' => 'medium',
                                'high' => 'high',
                                'xhigh' => 'max',
                            ],
                        ],
                    ],
                ],
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
                            'input' => ['text', 'image'],
                            'reasoning' => false,
                        ],
                    ],
                ],
            ],
        ];
    }
}
