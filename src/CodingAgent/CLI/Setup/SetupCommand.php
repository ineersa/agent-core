<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Setup;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\CodingAgent\CLI\Providers\ProvidersSetupFlow;
use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\SettingsLayerEnum;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SetupSettingsService;
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
use Symfony\Component\Tui\Tui;

#[AsCommand(name: 'setup', description: 'Configure Hatfield settings, providers and extensions')]
final readonly class SetupCommand
{
    public function __construct(
        private AiCatalog $catalog,
        private SettingsOverrideWriter $writer,
        private AppConfig $config,
        private ExtensionsSetupFlow $extensions,
        private CoreSettingsScreen $settings,
        private SetupSettingsService $settingsService,
        private ThemeRegistry $themes,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SymfonyStyle $io, InputInterface $input, #[Option(description: 'Edit project settings instead of user settings')] bool $project = false): int
    {
        if (!$input->isInteractive() || !stream_isatty(\STDIN) || !stream_isatty(\STDOUT)) {
            $io->error('Hatfield setup needs an interactive terminal.');

            return Command::FAILURE;
        }
        $layer = $project ? SettingsLayerEnum::Project : SettingsLayerEnum::User;
        $theme = new DefaultTheme($this->themes->getOrThrow($this->config->tui->theme));
        $tui = new Tui();
        (new UnifiedSetupScreen($theme))->mount($tui, $layer, function (SettingsLayerEnum $scope) use ($theme): array {
            $app = $this->settingsService->loadConfig();
            $providers = ProvidersSetupFlow::for($this->catalog, $this->writer, $app, SettingsLayerEnum::Project === $scope);
            $extensions = clone $this->extensions;
            $extensions->layer = $scope;

            return [
                $this->settingsService->path($scope),
                $this->settings,
                $providers,
                new SetupScreen($providers, $this->logger, $theme),
                new ExtensionsSetupScreen($extensions, $theme, $this->logger),
            ];
        });
        $tui->run();

        return Command::SUCCESS;
    }
}
