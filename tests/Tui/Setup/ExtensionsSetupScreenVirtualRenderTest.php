<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Setup;

use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Extension\ExtensionPackageInstaller;
use Ineersa\CodingAgent\Tests\Support\ExtensionsSetupTestSupport;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Setup\ExtensionsSetupFlowInterface;
use Ineersa\Tui\Setup\ExtensionsSetupScreen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\SettingsListWidget;
use Symfony\Component\Yaml\Yaml;

/**
 * Thesis: ExtensionsSetupScreen drives real keyboard editing through the native
 * settings list into persisted YAML via the real ExtensionsSetupFlow (offline
 * Composer shim only). Scope can switch interactively; cancellation before Save
 * leaves settings unchanged.
 */
#[CoversClass(ExtensionsSetupScreen::class)]
final class ExtensionsSetupScreenVirtualRenderTest extends TestCase
{
    private string $tmpDir = '';
    private string $homeDir = '';
    private string $projectDir = '';
    private string $catalogPath = '';
    private string $originalPath = '';

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('extensions_setup_screen');
        $this->homeDir = $this->tmpDir.'/home';
        $this->projectDir = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        TestDirectoryIsolation::ensureDirectory($this->projectDir.'/.hatfield');
        TestDirectoryIsolation::ensureDirectory($this->tmpDir.'/config');
        file_put_contents($this->tmpDir.'/config/hatfield.defaults.yaml', ExtensionsSetupTestSupport::defaultsYaml());
        $this->catalogPath = $this->tmpDir.'/config/hatfield-provided-extensions.yaml';
        file_put_contents($this->catalogPath, ExtensionsSetupTestSupport::catalogYaml());
        $shim = ExtensionsSetupTestSupport::installFakeComposerOnPath($this->tmpDir);
        $this->originalPath = $shim['originalPath'];
    }

    protected function tearDown(): void
    {
        ExtensionsSetupTestSupport::restorePath($this->originalPath);
        if ('' !== $this->tmpDir) {
            TestDirectoryIsolation::removeDirectory($this->tmpDir);
        }
    }

    #[Test]
    public function pickerRendersAllFourExtensionsAndScopeHint(): void
    {
        [$screen, $terminal] = $this->mount($this->realFlow(project: true));

        $text = $this->plain($terminal);
        $this->assertStringContainsString('Hatfield Extensions Setup', $text);
        $this->assertStringContainsString('SafeGuard', $text);
        $this->assertStringContainsString('Task Workflow', $text);
        $this->assertStringContainsString('File Rewind', $text);
        $this->assertStringContainsString('Observational Memory', $text);
        $this->assertStringContainsString('project (.hatfield/settings.yaml)', $text);
        $this->assertStringContainsString('Change settings scope', $text);
        $this->assertSame('picker', $this->phase($screen));
    }

    #[Test]
    public function keyboardScopeSwitchThenTextEditsPersistUserYamlWithoutProjectOnlyValues(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/settings.yaml', <<<'YAML'
extensions:
    settings:
        file_rewind:
            enabled: true
            max_retained_turns: 77
            git_timeout_seconds: 11
YAML);

        $installer = new ExtensionPackageInstaller(new SettingsPathResolver($this->projectDir, $this->homeDir));
        $installer->ensureInstalled(
            SettingsLayerEnum::User,
            $this->projectDir,
            'ineersa/hatfield-ext-file-rewind',
            '0.0.42',
        );

        [$screen, $terminal, $tui] = $this->mount($this->realFlow(project: true));

        // Picker rows: SafeGuard, Task Workflow, File Rewind, OM, Change scope, Done.
        for ($i = 0; $i < 4; ++$i) {
            $tui->handleInput("\x1b[B");
        }
        $tui->handleInput("\r"); // Change settings scope
        $this->assertSame('scope', $this->phase($screen));
        $tui->handleInput("\x1b[B"); // User
        $tui->handleInput("\r");
        $this->assertSame('picker', $this->phase($screen));
        $this->assertStringContainsString('user (~/.hatfield/settings.yaml)', $this->plain($terminal));

        // Select File Rewind (index 2).
        $tui->handleInput("\x1b[B");
        $tui->handleInput("\x1b[B");
        $tui->handleInput("\r");
        $this->assertSame('action', $this->phase($screen));
        $tui->handleInput("\r"); // Configure
        $this->assertSame('settings', $this->phase($screen));

        // Prefill must exclude project-only 77/11 values.
        $this->assertStringContainsString('100', $this->plain($terminal));
        $this->assertStringNotContainsString('77', $this->plain($terminal));

        // Enable for parent.
        $tui->handleInput("\r");

        // Move to Max retained turns (parent + 3 child + enabled = index 5).
        for ($i = 0; $i < 5; ++$i) {
            $tui->handleInput("\x1b[B");
        }
        $tui->handleInput("\r"); // open SettingsTextInputWidget
        $this->assertInstanceOf(
            \Ineersa\Tui\Setup\SettingsTextInputWidget::class,
            $this->activeSubmenu($screen),
        );
        // Clear default "100" then type 55.
        $tui->handleInput("\x7f");
        $tui->handleInput("\x7f");
        $tui->handleInput("\x7f");
        $tui->handleInput('55');
        $tui->handleInput("\r"); // commit text edit
        $this->assertNull($this->activeSubmenu($screen));
        $this->assertSame('55', $this->settingsWidget($screen)->getValue('fr.max_retained_turns'));

        // Move to Git timeout (next row) and edit 30 → 12.
        $tui->handleInput("\x1b[B");
        $tui->handleInput("\r");
        $tui->handleInput("\x7f");
        $tui->handleInput("\x7f");
        $tui->handleInput('12');
        $tui->handleInput("\r");
        $this->assertSame('12', $this->settingsWidget($screen)->getValue('fr.git_timeout_seconds'));

        // Return to Save (one more down) and save.
        $tui->handleInput("\x1b[B");
        $this->assertSame('↵', $this->settingsWidget($screen)->getValue('save'));
        $tui->handleInput("\r");
        $this->assertSame('picker', $this->phase($screen));

        $userParsed = Yaml::parseFile($this->homeDir.'/.hatfield/settings.yaml');
        $this->assertContains(ExtensionsSetupTestSupport::FILE_REWIND, $userParsed['extensions']['enabled']);
        $this->assertSame(55, $userParsed['extensions']['settings']['file_rewind']['max_retained_turns']);
        $this->assertSame(12, $userParsed['extensions']['settings']['file_rewind']['git_timeout_seconds']);
        $this->assertTrue($userParsed['extensions']['settings']['file_rewind']['enabled']);

        $projectParsed = Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml');
        $this->assertSame(77, $projectParsed['extensions']['settings']['file_rewind']['max_retained_turns']);
        $this->assertSame(11, $projectParsed['extensions']['settings']['file_rewind']['git_timeout_seconds']);
        $this->assertArrayNotHasKey('enabled', $projectParsed['extensions'] ?? []);
    }

    #[Test]
    public function keyboardConfigureInstallAndSavePersistsYamlThroughRealFlow(): void
    {
        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        file_put_contents($settingsPath, "tui:\n    theme: cyberpunk\nextensions:\n    settings:\n        unrelated_extension:\n            keep: true\n");

        [$screen, $terminal, $tui] = $this->mount($this->realFlow(project: true));

        // Picker: SafeGuard, Task Workflow, File Rewind (third row).
        $tui->handleInput("\x1b[B");
        $tui->handleInput("\x1b[B");
        $tui->handleInput("\r");
        $this->assertSame('action', $this->phase($screen));

        $tui->handleInput("\r"); // Configure
        $this->assertSame('confirm', $this->phase($screen));
        $this->assertStringContainsString('Install ineersa/hatfield-ext-file-rewind:0.0.42', $this->plain($terminal));

        $tui->handleInput("\r"); // Confirm Composer install
        $this->assertSame('settings', $this->phase($screen));
        $this->assertFileExists($this->projectDir.'/.hatfield/extensions/vendor/autoload.php');

        $tui->handleInput("\r"); // enable parent
        // Move to Max retained turns and change 100 → 88.
        for ($i = 0; $i < 5; ++$i) {
            $tui->handleInput("\x1b[B");
        }
        $tui->handleInput("\r");
        $tui->handleInput("\x7f");
        $tui->handleInput("\x7f");
        $tui->handleInput("\x7f");
        $tui->handleInput('88');
        $tui->handleInput("\r");
        $this->assertSame('88', $this->settingsWidget($screen)->getValue('fr.max_retained_turns'));

        // Down to Save (git timeout + save).
        $tui->handleInput("\x1b[B");
        $tui->handleInput("\x1b[B");
        $tui->handleInput("\r");

        $this->assertSame('picker', $this->phase($screen));
        $parsed = Yaml::parseFile($settingsPath);
        $this->assertSame('cyberpunk', $parsed['tui']['theme']);
        $this->assertTrue($parsed['extensions']['settings']['unrelated_extension']['keep']);
        $this->assertContains(ExtensionsSetupTestSupport::FILE_REWIND, $parsed['extensions']['enabled']);
        $this->assertTrue($parsed['extensions']['settings']['file_rewind']['enabled']);
        $this->assertSame(88, $parsed['extensions']['settings']['file_rewind']['max_retained_turns']);
    }

    #[Test]
    public function cancelFromSettingsBeforeSaveLeavesYamlUnchanged(): void
    {
        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        $before = "tui:\n    theme: oh-p-dark\n";
        file_put_contents($settingsPath, $before);

        $installer = new ExtensionPackageInstaller(new SettingsPathResolver($this->projectDir, $this->homeDir));
        $installer->ensureInstalled(
            SettingsLayerEnum::Project,
            $this->projectDir,
            'ineersa/hatfield-ext-file-rewind',
            '0.0.42',
        );

        [$screen, $terminal, $tui] = $this->mount($this->realFlow(project: true));
        $tui->handleInput("\x1b[B");
        $tui->handleInput("\x1b[B");
        $tui->handleInput("\r"); // File Rewind
        $tui->handleInput("\r"); // Configure
        $this->assertSame('settings', $this->phase($screen));
        $this->assertStringContainsString('File Rewind settings', $this->plain($terminal));

        $tui->handleInput("\r"); // toggle parent enabled
        $tui->handleInput("\x1b"); // Esc cancel
        $this->assertSame('action', $this->phase($screen));
        $this->assertSame($before, file_get_contents($settingsPath));
    }

    #[Test]
    public function failedComposerConfirmLeavesSettingsUnchanged(): void
    {
        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        $before = "tui:\n    theme: cyberpunk\n";
        file_put_contents($settingsPath, $before);

        $failMarker = $this->tmpDir.'/fail-composer';
        file_put_contents($failMarker, '1');
        putenv('FAKE_COMPOSER_FAIL_MARKER='.$failMarker);
        $_ENV['FAKE_COMPOSER_FAIL_MARKER'] = $failMarker;
        $_SERVER['FAKE_COMPOSER_FAIL_MARKER'] = $failMarker;

        [$screen, $terminal, $tui] = $this->mount($this->realFlow(project: true));
        $tui->handleInput("\x1b[B");
        $tui->handleInput("\x1b[B");
        $tui->handleInput("\r");
        $tui->handleInput("\r");
        $this->assertSame('confirm', $this->phase($screen));
        $tui->handleInput("\r"); // Yes → install fails

        $this->assertSame('action', $this->phase($screen));
        $this->assertStringContainsString('Composer require failed', $this->errorText($screen));
        $this->assertSame($before, file_get_contents($settingsPath));
        $this->assertStringNotContainsString('extensions:', (string) file_get_contents($settingsPath));
    }

    /**
     * @return array{0: ExtensionsSetupScreen, 1: VirtualTerminal, 2: Tui}
     */
    private function mount(ExtensionsSetupFlowInterface $flow, int $columns = 110): array
    {
        $terminal = new VirtualTerminal(columns: $columns, rows: 40);
        $tui = new Tui(terminal: $terminal);
        $screen = new ExtensionsSetupScreen($flow, ExtensionsSetupTestSupport::createTheme());
        $screen->mount($tui);
        $tui->start();
        $tui->requestRender(force: true);
        $tui->processRender();

        return [$screen, $terminal, $tui];
    }

    private function realFlow(bool $project): \Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow
    {
        return ExtensionsSetupTestSupport::createFlowFromAppConfig(
            $this->tmpDir,
            $this->projectDir,
            $this->homeDir,
            $this->catalogPath,
            $project,
        );
    }

    private function plain(VirtualTerminal $terminal): string
    {
        $buffer = new ScreenBuffer(
            width: $terminal->getColumns(),
            height: $terminal->getRows(),
        );
        $buffer->write($terminal->getOutput());

        return $buffer->getScreen();
    }

    private function phase(ExtensionsSetupScreen $screen): string
    {
        return (new \ReflectionProperty(ExtensionsSetupScreen::class, 'phase'))->getValue($screen);
    }

    private function errorText(ExtensionsSetupScreen $screen): string
    {
        $errorWidget = (new \ReflectionProperty(ExtensionsSetupScreen::class, 'errorWidget'))->getValue($screen);

        return $errorWidget->getText();
    }

    private function settingsWidget(ExtensionsSetupScreen $screen): SettingsListWidget
    {
        $widget = (new \ReflectionProperty(ExtensionsSetupScreen::class, 'settingsWidget'))->getValue($screen);
        $this->assertInstanceOf(SettingsListWidget::class, $widget);

        return $widget;
    }

    private function activeSubmenu(ExtensionsSetupScreen $screen): mixed
    {
        $widget = $this->settingsWidget($screen);

        return (new \ReflectionProperty(SettingsListWidget::class, 'activeSubmenu'))->getValue($widget);
    }
}
