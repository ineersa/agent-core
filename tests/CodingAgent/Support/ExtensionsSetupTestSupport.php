<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Support;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ProvidedExtensionsCatalog;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\ExtensionPackageInstaller;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Theme\TuiTheme;
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
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Shared offline fixtures for extensions:setup flow/screen/catalog tests.
 *
 * Helpers stay tests-only. Callers must restore getenv/$_ENV/$_SERVER.
 */
final class ExtensionsSetupTestSupport
{
    public const string SAFE_GUARD = 'Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension';
    public const string FILE_REWIND = 'Ineersa\\HatfieldExt\\FileRewind\\FileRewindExtension';
    public const string TASK_WORKFLOW = 'Ineersa\\HatfieldExt\\TaskWorkflow\\TaskWorkflowExtension';

    public static function catalogYaml(): string
    {
        return <<<'YAML'
packages:
    - id: safe-guard
      name: SafeGuard
      builtin: true
      extension_class: Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
      settings_key: safe_guard
      guided_fields:
          - id: sg.auto_deny_in_noninteractive
            path: auto_deny_in_noninteractive
            type: bool
            label: Auto-deny noninteractive
            description: Block instead of RequireApproval when no approval channel is present.
            default: true
          - id: sg.allow_write_outside_cwd
            path: allow_write_outside_cwd
            type: absolute_path_list
            label: Allow write outside CWD
            description: Absolute paths.
            default: []
    - id: task-workflow
      name: Task Workflow
      builtin: false
      package: ineersa/hatfield-ext-task-workflow
      version: 0.0.42
      extension_class: Ineersa\HatfieldExt\TaskWorkflow\TaskWorkflowExtension
      settings_key: task_workflow
      guided_fields:
          - id: tw.task_root
            path: task_root
            type: path_string
            label: Task board root
            description: Board root.
            default: ''
    - id: file-rewind
      name: File Rewind
      builtin: false
      package: ineersa/hatfield-ext-file-rewind
      version: 0.0.42
      extension_class: Ineersa\HatfieldExt\FileRewind\FileRewindExtension
      settings_key: file_rewind
      guided_fields:
          - id: fr.enabled
            path: enabled
            type: bool
            label: File Rewind active
            description: Active flag.
            default: true
          - id: fr.max_retained_turns
            path: max_retained_turns
            type: positive_int
            label: Max retained turns
            description: Retention.
            default: 100
          - id: fr.git_timeout_seconds
            path: git_timeout_seconds
            type: positive_int
            label: Git timeout seconds
            description: Timeout.
            default: 30
    - id: observational-memory
      name: Observational Memory
      builtin: false
      package: ineersa/hatfield-ext-observational-memory
      version: 0.0.42
      extension_class: Ineersa\HatfieldExt\ObservationalMemory\ObservationalMemoryExtension
      settings_key: observational_memory
      guided_fields:
          - id: om.model
            path: model
            type: model_id
            label: Shared model
            description: Exact provider/model.
            default: ''
          - id: om.database
            path: storage.database
            type: path_string
            label: Database path
            description: SQLite path.
            default: .hatfield/extensions-data/observational-memory/om.sqlite
          - id: om.context_window_ratio
            path: observer.context_window_ratio
            type: open_unit_ratio
            label: Observer context ratio
            description: Open unit ratio.
            default: 0.65
          - id: om.reflect_after_observation_tokens
            path: reflector.reflect_after_observation_tokens
            type: positive_int
            label: Reflect after tokens
            description: Reflect threshold.
            default: 40000
          - id: om.observations_max_tokens
            path: pools.observations_max_tokens
            type: positive_int
            label: Observations pool max tokens
            description: Pool max.
            default: 30000
YAML;
    }

    public static function defaultsYaml(): string
    {
        return <<<'YAML'
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
YAML;
    }

    public static function createSerializer(): SerializerInterface
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

    public static function createValidator(): ValidatorInterface
    {
        return Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();
    }

    public static function createCatalog(string $catalogPath, ?SerializerInterface $serializer = null, ?ValidatorInterface $validator = null): ProvidedExtensionsCatalog
    {
        return new ProvidedExtensionsCatalog(
            $catalogPath,
            $serializer ?? self::createSerializer(),
            $validator ?? self::createValidator(),
        );
    }

    public static function createFlow(
        string $tmpDir,
        string $projectDir,
        string $homeDir,
        string $catalogPath,
        bool $project = true,
    ): ExtensionsSetupFlow {
        $pathResolver = new SettingsPathResolver($projectDir, $homeDir);
        $serializer = self::createSerializer();

        return new ExtensionsSetupFlow(
            self::createCatalog($catalogPath, $serializer),
            new ExtensionPackageInstaller($pathResolver),
            new AppConfigLoader($pathResolver),
            new AppResourceLocator($tmpDir),
            new SettingsOverrideWriter($pathResolver, PropertyAccess::createPropertyAccessor(), new Filesystem()),
            $pathResolver,
            $serializer,
            $serializer,
            self::createValidator(),
            $project ? SettingsLayerEnum::Project : SettingsLayerEnum::User,
            $projectDir,
        );
    }

    public static function createFlowFromAppConfig(
        string $tmpDir,
        string $projectDir,
        string $homeDir,
        string $catalogPath,
        bool $project = true,
    ): ExtensionsSetupFlow {
        $pathResolver = new SettingsPathResolver($projectDir, $homeDir);
        $serializer = self::createSerializer();

        return ExtensionsSetupFlow::for(
            self::createCatalog($catalogPath, $serializer),
            new ExtensionPackageInstaller($pathResolver),
            new AppConfigLoader($pathResolver),
            new AppResourceLocator($tmpDir),
            new SettingsOverrideWriter($pathResolver, PropertyAccess::createPropertyAccessor(), new Filesystem()),
            $pathResolver,
            $serializer,
            $serializer,
            self::createValidator(),
            new AppConfig(
                tui: new TuiConfig(theme: 'cyberpunk'),
                logging: new LoggingConfig(logDir: $tmpDir.'/logs'),
                cwd: $projectDir,
            ),
            project: $project,
        );
    }

    public static function createTheme(): TuiTheme
    {
        return new DefaultTheme(new ThemePalette('test', [
            ThemeColorEnum::Header->value => 'cyan',
            ThemeColorEnum::Error->value => 'red',
            ThemeColorEnum::Muted->value => 'bright_black',
            ThemeColorEnum::Accent->value => 'cyan',
        ]));
    }

    /**
     * Install a PATH composer shim that runs the offline fake-composer fixture.
     *
     * @return array{pathDir: string, originalPath: string}
     */
    public static function installFakeComposerOnPath(string $tmpDir): array
    {
        $fakeComposer = \dirname(__DIR__).'/Extension/Support/fake-composer.php';
        $pathDir = $tmpDir.'/bin';
        TestDirectoryIsolation::ensureDirectory($pathDir);
        file_put_contents($pathDir.'/composer', "#!/usr/bin/env php\n<?php\nrequire ".var_export($fakeComposer, true).";\n");
        chmod($pathDir.'/composer', 0755);
        $originalPath = (string) getenv('PATH');
        $path = $pathDir.(\PATH_SEPARATOR.($originalPath ?: '/usr/bin:/bin'));
        putenv('PATH='.$path);
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;
        putenv('FAKE_COMPOSER_FAIL_MARKER');
        unset($_ENV['FAKE_COMPOSER_FAIL_MARKER'], $_SERVER['FAKE_COMPOSER_FAIL_MARKER']);

        return ['pathDir' => $pathDir, 'originalPath' => $originalPath];
    }

    public static function restorePath(string $originalPath): void
    {
        putenv('FAKE_COMPOSER_FAIL_MARKER');
        unset($_ENV['FAKE_COMPOSER_FAIL_MARKER'], $_SERVER['FAKE_COMPOSER_FAIL_MARKER']);
        putenv('PATH='.$originalPath);
        $_ENV['PATH'] = $originalPath;
        $_SERVER['PATH'] = $originalPath;
    }
}
