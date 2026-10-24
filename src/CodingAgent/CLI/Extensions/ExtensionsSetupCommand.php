<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\ProvidedExtensionsCatalog;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Extension\ExtensionPackageInstaller;
use Ineersa\Tui\Setup\ExtensionsSetupScreen;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Boots the standalone extensions:setup TUI ({@see ExtensionsSetupScreen}).
 *
 * No console-wizard fallback — requires an interactive terminal.
 * Default target is project settings; pass --user for ~/.hatfield.
 */
#[AsCommand(name: 'extensions:setup', description: 'Interactive setup for Hatfield-provided extensions')]
final class ExtensionsSetupCommand
{
    public function __construct(
        private readonly ProvidedExtensionsCatalog $catalog,
        private readonly ExtensionPackageInstaller $installer,
        private readonly AppConfigLoader $configLoader,
        private readonly AppResourceLocator $resourceLocator,
        private readonly SettingsOverrideWriter $settingsWriter,
        private readonly SettingsPathResolver $pathResolver,
        private readonly AppConfig $appConfig,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Write to ~/.hatfield/settings.yaml instead of project .hatfield/settings.yaml')]
        bool $user = false,
        ?InputInterface $input = null,
    ): int {
        if ((null !== $input && !$input->isInteractive()) || !$this->isInteractiveTerminal()) {
            $io->error('extensions:setup needs an interactive terminal.');

            return Command::FAILURE;
        }

        try {
            $packages = $this->catalog->all();
        } catch (\Throwable $e) {
            $io->error('Provided extensions catalog could not be loaded: '.$e->getMessage());

            return Command::FAILURE;
        }

        if ([] === $packages) {
            $io->error('No Hatfield-provided extensions are listed in the catalog.');

            return Command::FAILURE;
        }

        $flow = ExtensionsSetupFlow::for(
            $this->catalog,
            $this->installer,
            $this->configLoader,
            $this->resourceLocator,
            $this->settingsWriter,
            $this->pathResolver,
            $this->appConfig,
            project: !$user,
        );
        $screen = new ExtensionsSetupScreen($flow);

        return $screen->run();
    }

    private function isInteractiveTerminal(): bool
    {
        if (\function_exists('stream_isatty')) {
            return stream_isatty(\STDIN) && stream_isatty(\STDOUT);
        }

        return \function_exists('posix_isatty')
            && @posix_isatty(\STDIN)
            && @posix_isatty(\STDOUT);
    }
}
