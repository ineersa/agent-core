<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Providers;

use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\Tui\Setup\SetupScreen;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Boots the standalone providers:setup TUI ({@see SetupScreen}).
 *
 * No console-wizard fallback — requires an interactive terminal.
 */
#[AsCommand(name: 'providers:setup', description: 'Interactive setup for AI providers')]
final class ProvidersSetupCommand
{
    public function __construct(
        private readonly AiCatalog $aiCatalog,
        private readonly SettingsOverrideWriter $settingsWriter,
        private readonly AppConfig $appConfig,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Write to project .hatfield/settings.yaml instead of ~/.hatfield/settings.yaml')]
        bool $project = false,
    ): int {
        if (!$this->isInteractiveTerminal()) {
            $io->error('providers:setup needs an interactive terminal.');

            return Command::FAILURE;
        }

        $flow = ProvidersSetupFlow::for(
            $this->aiCatalog,
            $this->settingsWriter,
            $this->appConfig,
            $project,
        );
        if ($flow->catalogEmpty()) {
            $io->error('No AI providers found. Ensure Hatfield is installed correctly.');

            return Command::FAILURE;
        }

        $screen = new SetupScreen($flow);

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
