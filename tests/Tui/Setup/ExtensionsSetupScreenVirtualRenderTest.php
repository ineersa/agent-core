<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Setup;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ProvidedExtensionsCatalog;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\ExtensionPackageInstaller;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Setup\ExtensionsSetupFlowInterface;
use Ineersa\Tui\Setup\ExtensionsSetupScreen;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Terminal\VirtualTerminal;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Yaml\Yaml;

/**
 * Thesis: ExtensionsSetupScreen drives real keyboard editing through the native
 * settings list into persisted YAML via the real ExtensionsSetupFlow (offline
 * Composer shim only). Cancellation before Save leaves settings unchanged.
 */
#[CoversClass(ExtensionsSetupScreen::class)]
final class ExtensionsSetupScreenVirtualRenderTest extends TestCase
{
    private const FILE_REWIND = 'Ineersa\\HatfieldExt\\FileRewind\\FileRewindExtension';

    private string $tmpDir = '';
    private string $homeDir = '';
    private string $projectDir = '';
    private string $pathDir = '';
    private string $originalPath = '';

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('extensions_setup_screen');
        $this->homeDir = $this->tmpDir.'/home';
        $this->projectDir = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        TestDirectoryIsolation::ensureDirectory($this->projectDir.'/.hatfield');
        TestDirectoryIsolation::ensureDirectory($this->tmpDir.'/config');

        file_put_contents($this->tmpDir.'/config/hatfield.defaults.yaml', <<<'YAML'
extensions:
    enabled:
        - Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
    settings:
        safe_guard:
            auto_deny_in_noninteractive: true
            allow_write_outside_cwd: []
            allow_command_patterns: []
            protected_read_patterns: []
            dangerous_command_patterns: []
forks:
    extensions:
        always_on:
            - Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
        enabled: []
agents:
    extensions:
        always_on:
            - Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
YAML);

        file_put_contents($this->tmpDir.'/config/hatfield-provided-extensions.yaml', <<<'YAML'
packages:
    - id: safe-guard
      name: SafeGuard
      builtin: true
      extension_class: Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
    - id: task-workflow
      name: Task Workflow
      builtin: false
      package: ineersa/hatfield-ext-task-workflow
      version: 0.0.42
      extension_class: Ineersa\HatfieldExt\TaskWorkflow\TaskWorkflowExtension
    - id: file-rewind
      name: File Rewind
      builtin: false
      package: ineersa/hatfield-ext-file-rewind
      version: 0.0.42
      extension_class: Ineersa\HatfieldExt\FileRewind\FileRewindExtension
    - id: observational-memory
      name: Observational Memory
      builtin: false
      package: ineersa/hatfield-ext-observational-memory
      version: 0.0.42
      extension_class: Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension
YAML);

        $fakeComposer = \dirname(__DIR__, 2).'/CodingAgent/Extension/Support/fake-composer.php';
        $this->pathDir = $this->tmpDir.'/bin';
        TestDirectoryIsolation::ensureDirectory($this->pathDir);
        file_put_contents($this->pathDir.'/composer', "#!/usr/bin/env php\n<?php\nrequire ".var_export($fakeComposer, true).";\n");
        chmod($this->pathDir.'/composer', 0755);
        $this->originalPath = (string) getenv('PATH');
        $path = $this->pathDir.(\PATH_SEPARATOR.($this->originalPath ?: '/usr/bin:/bin'));
        putenv('PATH='.$path);
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;
        putenv('FAKE_COMPOSER_FAIL_MARKER');
        unset($_ENV['FAKE_COMPOSER_FAIL_MARKER'], $_SERVER['FAKE_COMPOSER_FAIL_MARKER']);
    }

    protected function tearDown(): void
    {
        putenv('FAKE_COMPOSER_FAIL_MARKER');
        unset($_ENV['FAKE_COMPOSER_FAIL_MARKER'], $_SERVER['FAKE_COMPOSER_FAIL_MARKER']);
        putenv('PATH='.$this->originalPath);
        $_ENV['PATH'] = $this->originalPath;
        $_SERVER['PATH'] = $this->originalPath;
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
        $this->assertSame('picker', $this->phase($screen));
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

        // Action: Configure.
        $tui->handleInput("\r");
        $this->assertSame('confirm', $this->phase($screen));
        $this->assertStringContainsString('Install ineersa/hatfield-ext-file-rewind:0.0.42', $this->plain($terminal));

        // Confirm Composer install.
        $tui->handleInput("\r");
        $this->assertSame('settings', $this->phase($screen));
        $this->assertFileExists($this->projectDir.'/.hatfield/extensions/vendor/autoload.php');

        // Enable for parent (first item yes/no; default false for missing class).
        $tui->handleInput("\r"); // toggle parentEnabled to yes
        // Move to Save (parent + 3 child + 3 file-rewind fields = index 7).
        for ($i = 0; $i < 7; ++$i) {
            $tui->handleInput("\x1b[B");
        }
        $tui->handleInput("\r"); // Save

        $this->assertSame('picker', $this->phase($screen));
        $parsed = Yaml::parseFile($settingsPath);
        $this->assertSame('cyberpunk', $parsed['tui']['theme']);
        $this->assertTrue($parsed['extensions']['settings']['unrelated_extension']['keep']);
        $this->assertContains(self::FILE_REWIND, $parsed['extensions']['enabled']);
        $this->assertTrue($parsed['extensions']['settings']['file_rewind']['enabled']);
        $this->assertSame(100, $parsed['extensions']['settings']['file_rewind']['max_retained_turns']);
    }

    #[Test]
    public function cancelFromSettingsBeforeSaveLeavesYamlUnchanged(): void
    {
        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        $before = "tui:\n    theme: oh-p-dark\n";
        file_put_contents($settingsPath, $before);

        // Preinstall so configure opens settings without Composer confirm.
        $installer = new ExtensionPackageInstaller(new SettingsPathResolver($this->projectDir, $this->homeDir));
        $installer->ensureInstalled(
            \Ineersa\CodingAgent\Config\SettingsLayerEnum::Project,
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
        $screen = new ExtensionsSetupScreen($flow);
        $screen->mount($tui);
        $tui->start();
        $tui->requestRender(force: true);
        $tui->processRender();

        return [$screen, $terminal, $tui];
    }

    private function realFlow(bool $project): ExtensionsSetupFlow
    {
        $pathResolver = new SettingsPathResolver($this->projectDir, $this->homeDir);
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory, new CamelCaseToSnakeCaseNameConverter());
        $propertyInfo = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
        $serializer = new Serializer([
            new ArrayDenormalizer(),
            new ObjectNormalizer($classMetadataFactory, $nameConverter, null, $propertyInfo),
        ]);

        return ExtensionsSetupFlow::for(
            new ProvidedExtensionsCatalog(
                $this->tmpDir.'/config/hatfield-provided-extensions.yaml',
                $serializer,
                Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
            ),
            new ExtensionPackageInstaller($pathResolver),
            new AppConfigLoader($pathResolver),
            new AppResourceLocator($this->tmpDir),
            new SettingsOverrideWriter($pathResolver, PropertyAccess::createPropertyAccessor(), new Filesystem()),
            $pathResolver,
            new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(logDir: $this->tmpDir.'/logs'),
                cwd: $this->projectDir,
            ),
            project: $project,
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
}
