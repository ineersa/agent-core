<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

use Ineersa\CodingAgent\Config\AppConfigResolver;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\Tui\Editor\PromptEditorWidget;
use Ineersa\Tui\Footer\FooterBarWidget;
use Ineersa\Tui\Footer\FooterDataProvider;
use Ineersa\Tui\Footer\FooterSegment;
use Ineersa\Tui\Footer\FooterSegmentProvider;
use Ineersa\Tui\Header\HeaderWidget;
use Ineersa\Tui\Layout\ChatLayout;
use Ineersa\Tui\Layout\TuiSlotRegistry;
use Ineersa\Tui\Status\WorkingStatusWidget;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemeLoader;
use Ineersa\Tui\Theme\ThemeRegistry;
use Ineersa\Tui\Theme\TuiTheme;
use Ineersa\Tui\Transcript\PendingMessagesWidget;
use Ineersa\Tui\Transcript\TranscriptWidget;
use Ineersa\Tui\Widget\TuiRenderContext;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Application-level TUI entry point.
 *
 * Receives an AgentSessionClient from the CLI command and runs the interactive
 * terminal UI. This is the only bridge between Symfony Console and Symfony TUI.
 *
 * Builds the default ChatLayout with all default widgets and slot registry.
 * Theme selection is driven by Hatfield settings via {@see AppConfigResolver}.
 *
 * Must not import Ineersa\AgentCore\Application, Infrastructure, or Messenger directly.
 * Must not receive raw RunEvent, command buses, stores, or agent-core services.
 */
final class InteractiveMode
{
    private TuiSlotRegistry $registry;
    private ChatLayout $layout;

    /** @var array<string, TuiTheme> Cache of resolved themes */
    private array $themeCache = [];

    public function __construct(
        private readonly AppConfigResolver $configResolver,
    ) {
    }

    /**
     * Run the interactive TUI for a given session client.
     *
     * @param AgentSessionClient   $client  The runtime session client
     * @param OutputInterface      $output  Output for rendering the layout
     * @param StartRunRequest|null $request Optional pre-configured start request
     */
    public function run(
        AgentSessionClient $client,
        OutputInterface $output,
        ?StartRunRequest $request = null,
        ?TuiTheme $theme = null,
    ): int {
        // Resolve Hatfield config for the target project cwd
        $projectCwd = $request->cwd ?? '';
        $appConfig = $this->configResolver->resolve($projectCwd);

        $theme ??= $this->createTheme(
            name: $appConfig->tui->theme,
            paths: $appConfig->tui->themePaths,
        );

        $this->buildLayout();

        // Detect terminal dimensions
        $width = 80;
        if ($output->isDecorated()) {
            $envWidth = getenv('COLUMNS');
            $width = false !== $envWidth && '' !== $envWidth ? (int) $envWidth : 80;
        }

        $context = new TuiRenderContext(terminalWidth: $width, theme: $theme);

        // Render the initial layout
        $lines = $this->layout->render($context);

        foreach ($lines as $line) {
            $output->writeln($line);
        }

        // If a prompt was provided, show brief status
        if (null !== $request) {
            $output->writeln('');
            $output->writeln(\sprintf('  <info>Run started: %s</info>', $request->prompt));
            $output->writeln('  <comment>Full TUI event loop coming in next iteration</comment>');
        }

        // @todo Wire actual Symfony Tui::run() event loop here.
        // When fully interactive, the flow will be:
        //   1. Create Symfony Tui instance
        //   2. Wrap TuiWidget implementations as AbstractWidget adapters
        //   3. Attach event listeners (SubmitEvent, InputEvent, TickEvent)
        //   4. Call $tui->run() to block until user quits
        //   5. Return exit code

        return Command::SUCCESS;
    }

    /**
     * Create the active theme from Hatfield config.
     *
     * Loads built-in themes from the configured search paths.
     * Falls back to 'cyberpunk' if the configured theme is not found.
     *
     * @param string       $name  Selected theme name from config
     * @param list<string> $paths Theme search directories (already resolved)
     */
    public function createTheme(string $name, array $paths): TuiTheme
    {
        $loader = new ThemeLoader();

        // Load all palettes from all theme paths
        $allPalettes = [];
        foreach ($paths as $path) {
            $palettes = $loader->loadDirectory($path);
            foreach ($palettes as $palette) {
                // First registration wins: built-in paths come first
                if (!isset($allPalettes[$palette->name])) {
                    $allPalettes[$palette->name] = $palette;
                }
            }
        }

        // Ensure built-in themes are always available even if config paths are wrong
        // Use a project-relative fallback from the app install dir
        $builtinPath = \dirname(__DIR__, 3).'/config/themes';
        $builtins = $loader->loadDirectory($builtinPath);
        foreach ($builtins as $palette) {
            if (!isset($allPalettes[$palette->name])) {
                $allPalettes[$palette->name] = $palette;
            }
        }

        $registry = new ThemeRegistry(
            builtin: array_values($allPalettes),
            defaultName: 'cyberpunk',
        );

        return new DefaultTheme($registry->getOrDefault($name));
    }

    /**
     * Build the default layout with all default widgets.
     */
    private function buildLayout(): void
    {
        $this->registry = new TuiSlotRegistry();

        // Build default widgets
        $header = new HeaderWidget();
        $transcript = new TranscriptWidget();
        $pendingMessages = new PendingMessagesWidget();
        $workingStatus = new WorkingStatusWidget();
        $editor = new PromptEditorWidget();
        $footerDataProvider = new FooterDataProvider();

        // Register built-in footer segments
        $footerDataProvider->addProvider(new class implements FooterSegmentProvider {
            /** @return list<FooterSegment> */
            public function getSegments(): array
            {
                return [
                    new FooterSegment(text: '◆ agent-core', priority: 0),
                ];
            }
        });

        $footer = new FooterBarWidget($footerDataProvider);

        $this->layout = new ChatLayout(
            registry: $this->registry,
            header: $header,
            transcript: $transcript,
            pendingMessages: $pendingMessages,
            workingStatus: $workingStatus,
            editor: $editor,
            footer: $footer,
        );
    }
}
