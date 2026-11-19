<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Setup;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\CodingAgent\CLI\Providers\ProvidersSetupFlow;
use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\Tui\Setup\CoreSettingsScreen;
use Ineersa\Tui\Setup\ExtensionsSetupScreen;
use Ineersa\Tui\Setup\SetupScreen;
use Ineersa\Tui\Setup\UnifiedSetupScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'setup', description: 'Configure Hatfield settings, providers and extensions')]
final readonly class SetupCommand
{
    public function __construct(
        private AiCatalog $catalog,
        private SettingsOverrideWriter $writer,
        private AppConfig $config,
        private ExtensionsSetupFlow $extensions,
        private CoreSettingsScreen $settings,
        private ThemeRegistry $themes,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SymfonyStyle $io, InputInterface $input, #[Option(description: 'Edit project settings instead of user settings')] bool $project = false): int
    {
        return $this->open($io, $input, $project);
    }

    public function open(SymfonyStyle $io, InputInterface $input, bool $project, ?int $tab = null): int
    {
        if (!$input->isInteractive() || !stream_isatty(\STDIN) || !stream_isatty(\STDOUT)) {
            $io->error('Hatfield setup needs an interactive terminal.');

            return Command::FAILURE;
        }
        $layer = $project ? SettingsLayerEnum::Project : SettingsLayerEnum::User;
        $providers = ProvidersSetupFlow::for($this->catalog, $this->writer, $this->config, $project);
        $this->extensions->layer = $layer;
        $theme = new DefaultTheme($this->themes->getOrThrow($this->config->tui->theme));
        $screen = new UnifiedSetupScreen($this->settings, new SetupScreen($providers, $this->logger), new ExtensionsSetupScreen($this->extensions, $theme, $this->logger), $theme);
        $screen->run($layer, $this->writer->layerPath($layer, $this->config->cwd), $providers, $tab ?? ([] === $providers->configuredModelRefs() ? 1 : 0));

        return Command::SUCCESS;
    }
}
