<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\TabSwitchCommand;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Runtime\TabService;
use Ineersa\Tui\Runtime\TuiRuntimeContext;

/**
 * POC: Registers the /tab slash command and applies tab switching.
 *
 * The /tab command lets the user switch between available tabs:
 *   /tab 2 — switch to tab 2 (1-indexed)
 *   /tab list — show available tabs
 *
 * Tab switching updates the TabService active index and syncs the
 * ChatScreen transcript to show the selected tab's blocks.
 *
 * This is POC/prototype code proving multi-tab TUI feasibility.
 */
final class TabRoutingListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $tabService = $context->tabService;
        $screen = $context->screen;

        $handler = new class($tabService, $screen) implements SlashCommandHandler {
            public function __construct(
                private readonly TabService $tabService,
                private readonly \Ineersa\Tui\Screen\ChatScreen $screen,
            ) {
            }

            public function handle(SlashCommand $command): \Ineersa\Tui\Command\CommandResult
            {
                $args = trim($command->args);

                if ('list' === $args) {
                    return $this->listTabs();
                }

                $index = (int) $args;

                if ($index < 1 || $index > $this->tabService->count()) {
                    return new TranscriptMessage(
                        \sprintf(
                            'Invalid tab index: %d. Available tabs: 1-%d. Use "/tab list" to see all tabs.',
                            $index,
                            $this->tabService->count(),
                        ),
                        'system',
                        'warning',
                    );
                }

                // Switch to the requested tab (1-indexed → 0-indexed)
                $zeroBased = $index - 1;
                $this->tabService->switchTo($zeroBased);

                $activeTab = $this->tabService->active();
                if (null !== $activeTab) {
                    // Update screen with the active tab's transcript
                    $this->screen->setTranscriptBlocks($activeTab->state->transcript);
                }

                return new TabSwitchCommand($zeroBased);
            }

            private function listTabs(): \Ineersa\Tui\Command\CommandResult
            {
                $lines = ['Available tabs:'];
                foreach ($this->tabService->tabs() as $i => $tab) {
                    $activeMarker = $i === $this->tabService->activeIndex() ? ' ← active' : '';
                    $runLabel = '' !== $tab->runId ? ' [run: '.$tab->runId.']' : '';
                    $lines[] = \sprintf(
                        '  %d. %s%s%s',
                        $i + 1,
                        $tab->label,
                        $runLabel,
                        $activeMarker,
                    );
                }

                return new TranscriptMessage(implode("\n", $lines), 'system');
            }
        };

        // Register idempotently — won't clash since /tab is new
        $this->commandRegistry->register(
            new CommandMetadata(
                name: 'tab',
                aliases: ['t'],
                description: 'Switch to a tab by index, or list tabs',
                usage: '/tab [list|<index>]',
                acceptsArguments: true,
            ),
            $handler,
        );
    }
}
