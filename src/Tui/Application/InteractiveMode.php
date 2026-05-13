<?php

declare(strict_types=1);

namespace Ineersa\Tui\Application;

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
 * Renders the initial layout to the console output for v1.
 *
 * Must not import Ineersa\AgentCore\Application, Infrastructure, or Messenger directly.
 * Must not receive raw RunEvent, command buses, stores, or agent-core services.
 */
final class InteractiveMode
{
    private TuiSlotRegistry $registry;
    private ChatLayout $layout;

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
    ): int {
        $this->buildLayout();

        // Detect terminal dimensions
        $width = 80;
        if ($output->isDecorated()) {
            // Attempt to get terminal width from environment
            $envWidth = getenv('COLUMNS');
            $width = false !== $envWidth ? (int) $envWidth : 80;
        }

        $context = new TuiRenderContext(terminalWidth: $width);

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
