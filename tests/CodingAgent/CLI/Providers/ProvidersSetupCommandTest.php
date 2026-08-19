<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Providers;

use Ineersa\CodingAgent\CLI\Providers\ProvidersSetupCommand;
use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
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

/**
 * Thesis: providers:setup refuses non-interactive / non-TTY runs before any writes.
 */
#[CoversClass(ProvidersSetupCommand::class)]
final class ProvidersSetupCommandTest extends TestCase
{
    private string $tmpDir;
    private string $homeDir;
    private string $projectDir;
    private string $catalogPath;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('providers_setup_cmd');
        $this->homeDir = $this->tmpDir.'/home';
        $this->projectDir = $this->tmpDir.'/project';
        TestDirectoryIsolation::ensureDirectory($this->homeDir.'/.hatfield');
        TestDirectoryIsolation::ensureDirectory($this->projectDir.'/.hatfield');
        $this->catalogPath = $this->tmpDir.'/ai-catalog.yaml';
        file_put_contents($this->catalogPath, "version: 1\nproviders: {}\n");
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    #[Test]
    public function nonInteractiveFailsWithInteractiveTerminalMessageAndWritesNothing(): void
    {
        $settingsPath = $this->homeDir.'/.hatfield/settings.yaml';
        $this->assertFileDoesNotExist($settingsPath);

        $pathResolver = new SettingsPathResolver($this->projectDir, $this->homeDir);
        $command = new ProvidersSetupCommand(
            new AiCatalog($this->catalogPath, $this->homeDir),
            new SettingsOverrideWriter(
                $pathResolver,
                PropertyAccess::createPropertyAccessor(),
                new Filesystem(),
            ),
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

        $status = $command($io, project: false, input: $input);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('needs an interactive terminal', $output->fetch());
        $this->assertFileDoesNotExist($settingsPath);
    }
}
