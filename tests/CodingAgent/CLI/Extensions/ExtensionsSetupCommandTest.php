<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Extensions;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupCommand;
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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
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
use Symfony\Component\Validator\Validation;

/**
 * Thesis: extensions:setup refuses non-interactive / non-TTY runs before any writes.
 */
#[CoversClass(ExtensionsSetupCommand::class)]
final class ExtensionsSetupCommandTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private string $projectDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('extensions_setup_cmd');
        $this->homeDir = $this->tmpDir.'/home';
        $this->projectDir = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        TestDirectoryIsolation::ensureDirectory($this->projectDir.'/.hatfield');
        TestDirectoryIsolation::ensureDirectory($this->tmpDir.'/config');
        file_put_contents($this->tmpDir.'/config/hatfield-provided-extensions.yaml', <<<'YAML'
packages:
    - id: safe-guard
      name: SafeGuard
      builtin: true
      extension_class: Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
YAML);
        file_put_contents($this->tmpDir.'/config/hatfield.defaults.yaml', "extensions:\n    enabled: []\n");
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    #[Test]
    public function nonInteractiveFailsWithInteractiveTerminalMessageAndWritesNothing(): void
    {
        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        $this->assertFileDoesNotExist($settingsPath);

        $pathResolver = new SettingsPathResolver($this->projectDir, $this->homeDir);
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $nameConverter = new MetadataAwareNameConverter($classMetadataFactory, new CamelCaseToSnakeCaseNameConverter());
        $propertyInfo = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);
        $serializer = new Serializer([
            new ArrayDenormalizer(),
            new ObjectNormalizer($classMetadataFactory, $nameConverter, null, $propertyInfo),
        ]);
        $command = new ExtensionsSetupCommand(
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
        );

        $input = new ArrayInput([]);
        $input->setInteractive(false);
        $output = new BufferedOutput();
        $io = new SymfonyStyle($input, $output);

        $status = $command($io, user: false, input: $input);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('extensions:setup needs an interactive terminal.', $output->fetch());
        $this->assertFileDoesNotExist($settingsPath);
        $this->assertFileDoesNotExist($this->homeDir.'/.hatfield/settings.yaml');
    }
}
