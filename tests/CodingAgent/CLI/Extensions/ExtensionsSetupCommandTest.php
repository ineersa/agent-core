<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Extensions;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupCommand;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\ExtensionPackageInstaller;
use Ineersa\CodingAgent\Tests\Support\ExtensionsSetupTestSupport;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\Theme\ThemeRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * Thesis: extensions:setup refuses non-interactive / non-TTY runs before any writes.
 */
#[CoversClass(ExtensionsSetupCommand::class)]
final class ExtensionsSetupCommandTest extends TestCase
{
    private string $tmpDir = '';
    private string $homeDir = '';
    private string $projectDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('extensions_setup_cmd');
        $this->homeDir = $this->tmpDir.'/home';
        $this->projectDir = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        TestDirectoryIsolation::ensureDirectory($this->projectDir.'/.hatfield');
        TestDirectoryIsolation::ensureDirectory($this->tmpDir.'/config');
        file_put_contents($this->tmpDir.'/config/hatfield-provided-extensions.yaml', ExtensionsSetupTestSupport::catalogYaml());
        file_put_contents($this->tmpDir.'/config/hatfield.defaults.yaml', ExtensionsSetupTestSupport::defaultsYaml());
    }

    protected function tearDown(): void
    {
        if ('' !== $this->tmpDir) {
            TestDirectoryIsolation::removeDirectory($this->tmpDir);
        }
    }

    #[Test]
    public function nonInteractiveFailsWithInteractiveTerminalMessageAndWritesNothing(): void
    {
        $settingsPath = $this->projectDir.'/.hatfield/settings.yaml';
        $this->assertFileDoesNotExist($settingsPath);

        $pathResolver = new SettingsPathResolver($this->projectDir, $this->homeDir);
        $serializer = ExtensionsSetupTestSupport::createSerializer();
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk', themePaths: []),
            logging: new LoggingConfig(logDir: $this->tmpDir.'/logs'),
            cwd: $this->projectDir,
        );
        $command = new ExtensionsSetupCommand(
            ExtensionsSetupTestSupport::createCatalog(
                $this->tmpDir.'/config/hatfield-provided-extensions.yaml',
                $serializer,
            ),
            new ExtensionPackageInstaller($pathResolver),
            new AppConfigLoader($pathResolver),
            new AppResourceLocator($this->tmpDir),
            new SettingsOverrideWriter($pathResolver, PropertyAccess::createPropertyAccessor(), new Filesystem()),
            $pathResolver,
            $appConfig,
            $serializer,
            $serializer,
            ExtensionsSetupTestSupport::createValidator(),
            new ThemeRegistry($appConfig, new AppResourceLocator($this->tmpDir), new NullLogger()),
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
