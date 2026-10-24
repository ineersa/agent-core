<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\Tui\Setup\ExtensionsSetupScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'extensions:setup', description: 'Install and configure Hatfield extensions')]
final readonly class ExtensionsSetupCommand
{
    public function __construct(
        private ExtensionsSetupFlow $flow,
        private AppConfig $config,
        private ThemeRegistry $themes,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Option(description: 'Start in user scope instead of project scope')]
        bool $user = false,
        ?InputInterface $input = null,
    ): int {
        if ((null !== $input && !$input->isInteractive()) || !stream_isatty(\STDIN) || !stream_isatty(\STDOUT)) {
            $io->error('extensions:setup needs an interactive terminal.');

            return Command::FAILURE;
        }
        $this->flow->layer = $user ? SettingsLayerEnum::User : SettingsLayerEnum::Project;
        $theme = new DefaultTheme($this->themes->getOrThrow($this->config->tui->theme));

        return (new ExtensionsSetupScreen($this->flow, $theme, $this->logger))->run();
    }
}
