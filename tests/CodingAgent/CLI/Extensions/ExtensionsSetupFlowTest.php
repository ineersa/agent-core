<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Extensions;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\ProvidedExtensionsCatalog;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Extension\ExtensionPackageInstaller;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Yaml\Yaml;

/**
 * Thesis: ExtensionsSetupFlow installs into the selected scope only, writes
 * enablement/settings after successful install, preserves unrelated keys, keeps
 * user scope free of project-only values, and validates child membership against
 * parent enablement.
 */
#[CoversClass(ExtensionsSetupFlow::class)]
final class ExtensionsSetupFlowTest extends TestCase
{
    private const SAFE_GUARD = 'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension';
    private const FILE_REWIND = 'Ineersa\\HatfieldExt\\FileRewind\\FileRewindExtension';
    private const TASK_WORKFLOW = 'Ineersa\\HatfieldExt\\TaskWorkflow\\TaskWorkflowExtension';

    private string $tmpDir;
    private string $homeDir;
    private string $projectDir;
    private string $defaultsPath;
    private string $catalogPath;
    private string $pathDir;
    private string $originalPath;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('extensions_setup_flow');
        $this->homeDir = $this->tmpDir.'/home';
        $this->projectDir = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        TestDirectoryIsolation::ensureDirectory($this->projectDir.'/.hatfield');

        TestDirectoryIsolation::ensureDirectory($this->tmpDir.'/config');
        $this->defaultsPath = $this->tmpDir.'/config/hatfield.defaults.yaml';
        file_put_contents($this->defaultsPath, <<<'YAML'
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

        $this->catalogPath = $this->tmpDir.'/config/hatfield-provided-extensions.yaml';
        file_put_contents($this->catalogPath, <<<'YAML'
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

        $fakeComposer = \dirname(__DIR__, 2).'/Extension/Support/fake-composer.php';
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
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    #[Test]
    public function projectSaveInstallsPackageWritesEnablementAndPreservesUnrelatedKeys(): void
    {
        $projectSettings = $this->projectDir.'/.hatfield/settings.yaml';
        file_put_contents($projectSettings, <<<'YAML'
tui:
    theme: cyberpunk
extensions:
    enabled:
        - Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
        - Acme\Unrelated\KeepMeExtension
    settings:
        unrelated_extension:
            keep: true
YAML);

        $flow = $this->flow(project: true);
        $this->assertTrue($flow->needsInstallConfirmation('file-rewind'));
        $flow->install('file-rewind');
        $this->assertFalse($flow->needsInstallConfirmation('file-rewind'));

        $state = $flow->formStateFor('file-rewind');
        $state['parentEnabled'] = true;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = true;
        $state['agentsAlwaysOn'] = false;
        $state['settings'] = [
            'enabled' => true,
            'max_retained_turns' => 42,
            'git_timeout_seconds' => 15,
        ];
        $flow->save($state);

        $parsed = Yaml::parseFile($projectSettings);
        $this->assertSame('cyberpunk', $parsed['tui']['theme']);
        $this->assertTrue($parsed['extensions']['settings']['unrelated_extension']['keep']);
        $this->assertContains(self::FILE_REWIND, $parsed['extensions']['enabled']);
        $this->assertContains('Acme\\Unrelated\\KeepMeExtension', $parsed['extensions']['enabled']);
        $this->assertSame(42, $parsed['extensions']['settings']['file_rewind']['max_retained_turns']);
        $this->assertContains(self::FILE_REWIND, $parsed['forks']['extensions']['enabled']);
        $this->assertFileExists($this->projectDir.'/.hatfield/extensions/vendor/autoload.php');
        $this->assertFileDoesNotExist($this->homeDir.'/.hatfield/settings.yaml');
    }

    #[Test]
    public function userScopeDoesNotImportProjectOnlyOverridesAndCannotReuseProjectPackage(): void
    {
        file_put_contents($this->projectDir.'/.hatfield/settings.yaml', <<<'YAML'
extensions:
    settings:
        task_workflow:
            task_root: /only/in/project
YAML);

        $installer = new ExtensionPackageInstaller(new SettingsPathResolver($this->projectDir, $this->homeDir));
        $installer->ensureInstalled(
            SettingsLayerEnum::Project,
            $this->projectDir,
            'ineersa/hatfield-ext-task-workflow',
            '0.0.42',
        );

        $flow = $this->flow(project: false);
        $state = $flow->formStateFor('task-workflow');
        $this->assertSame('', $state['settings']['task_root']);
        $this->assertTrue($flow->needsInstallConfirmation('task-workflow'));

        $flow->install('task-workflow');
        $state = $flow->formStateFor('task-workflow');
        $state['parentEnabled'] = true;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $state['settings'] = ['task_root' => '/user/board'];
        $flow->save($state);

        $userParsed = Yaml::parseFile($this->homeDir.'/.hatfield/settings.yaml');
        $this->assertSame('/user/board', $userParsed['extensions']['settings']['task_workflow']['task_root']);
        $this->assertSame('/only/in/project', Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml')['extensions']['settings']['task_workflow']['task_root']);
        $this->assertFileExists($this->homeDir.'/.hatfield/extensions/vendor/autoload.php');
    }

    #[Test]
    public function projectCanReuseUserInstalledPackageWithoutComposerRequire(): void
    {
        $installer = new ExtensionPackageInstaller(new SettingsPathResolver($this->projectDir, $this->homeDir));
        $installer->ensureInstalled(
            SettingsLayerEnum::User,
            $this->projectDir,
            'ineersa/hatfield-ext-file-rewind',
            '0.0.42',
        );

        $flow = $this->flow(project: true);
        $this->assertFalse($flow->needsInstallConfirmation('file-rewind'));
        $state = $flow->formStateFor('file-rewind');
        $state['parentEnabled'] = true;
        $state['forksAlwaysOn'] = false;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;
        $flow->save($state);

        $this->assertFileDoesNotExist($this->projectDir.'/.hatfield/extensions/composer.json');
        $parsed = Yaml::parseFile($this->projectDir.'/.hatfield/settings.yaml');
        $this->assertContains(self::FILE_REWIND, $parsed['extensions']['enabled']);
    }

    #[Test]
    public function failedInstallLeavesSettingsUnchanged(): void
    {
        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        file_put_contents($settingsPath, "tui:\n    theme: cyberpunk\n");
        $before = (string) file_get_contents($settingsPath);

        $failMarker = $this->tmpDir.'/fail-composer';
        file_put_contents($failMarker, '1');
        putenv('FAKE_COMPOSER_FAIL_MARKER='.$failMarker);
        $_ENV['FAKE_COMPOSER_FAIL_MARKER'] = $failMarker;
        $_SERVER['FAKE_COMPOSER_FAIL_MARKER'] = $failMarker;

        $flow = $this->flow(project: true);
        try {
            $flow->install('observational-memory');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Composer require failed', $e->getMessage());
        }

        $this->assertSame($before, file_get_contents($settingsPath));
        $this->assertFalse($flow->wroteSomething());
    }

    #[Test]
    public function childSelectionWithoutParentEnablementIsRejected(): void
    {
        $flow = $this->flow(project: true);
        $state = $flow->formStateFor('safe-guard');
        $state['parentEnabled'] = false;
        $state['forksAlwaysOn'] = true;
        $state['forksEnabled'] = false;
        $state['agentsAlwaysOn'] = false;

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Child selections require the extension to be enabled for the parent');
        $flow->save($state);
    }

    #[Test]
    public function safeGuardNeedsNoComposerInstall(): void
    {
        $flow = $this->flow(project: true);
        $this->assertFalse($flow->needsInstallConfirmation('safe-guard'));
        $rows = $flow->extensionRows();
        $safe = null;
        foreach ($rows as $row) {
            if ('safe-guard' === $row['id']) {
                $safe = $row;
                break;
            }
        }
        $this->assertNotNull($safe);
        $this->assertTrue($safe['builtin']);
        $this->assertFalse($safe['needsInstall']);
        $this->assertStringContainsString('built-in', $safe['status']);
        $this->assertContains(self::SAFE_GUARD, [$safe['extensionClass']]);
        $this->assertSame(self::TASK_WORKFLOW, $flow->formStateFor('task-workflow')['extensionClass']);
    }

    private function flow(bool $project): ExtensionsSetupFlow
    {
        $pathResolver = new SettingsPathResolver($this->projectDir, $this->homeDir);
        $catalog = new ProvidedExtensionsCatalog(
            $this->catalogPath,
            $this->createSerializer(),
            Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator(),
        );

        return new ExtensionsSetupFlow(
            $catalog,
            new ExtensionPackageInstaller($pathResolver),
            new AppConfigLoader($pathResolver),
            new AppResourceLocator($this->tmpDir),
            new SettingsOverrideWriter($pathResolver, PropertyAccess::createPropertyAccessor(), new Filesystem()),
            $pathResolver,
            $project ? SettingsLayerEnum::Project : SettingsLayerEnum::User,
            $this->projectDir,
        );
    }

    private function createSerializer(): SerializerInterface
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory, new CamelCaseToSnakeCaseNameConverter());
        $propertyInfo = new PropertyInfoExtractor(
            [],
            [new PhpDocExtractor(), new ReflectionExtractor()],
        );

        return new Serializer([
            new ArrayDenormalizer(),
            new ObjectNormalizer(
                $classMetadataFactory,
                $nameConverter,
                null,
                $propertyInfo,
            ),
        ], [new JsonEncoder()]);
    }
}
