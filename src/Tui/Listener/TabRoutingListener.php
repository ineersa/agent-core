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
 * Registers the /tab slash command for tab switching and listing.
 *
 * The /tab command lets the user switch between available tabs:
 *
 *   /tab list        — show available tabs
 *   /tab <N>         — switch to tab N (1-indexed)
 *
 * Tabs are created automatically by {@see SubagentTabAutoListener}
 * when a completed subagent artifact is detected in the parent transcript.
 *
 * This is POC/prototype code proving multi-tab TUI feasibility.
 *
 * ## Blocker: true interactive subagent child-run tabs
 *
 * The current POC opens read-only tabs backed by child run events, not
 * interactive subagent tabs. True subagent-child interactive tabs (where a
 * subagent fork runs as a child TUI tab with its own editor, event polling,
 * model controls) are blocked by:
 *
 * 1. **No exposed child RunHandle/client lifecycle** — SubagentExecutionService
 *    runs the child synchronously within the parent LLM loop and does not
 *    expose a RunHandle, AgentSessionClient, or event stream for the child.
 *    There is no way to poll child events from the parent TUI.
 * 2. **Child events are parent-artifact scoped** — The child run's events are
 *    stored under the parent run's artifact directory, not as an independent
 *    session with its own event stream.
 * 3. **SubagentExecutionService cancels WaitingHuman** — When the subagent
 *    encounters a WaitingHuman response, the execution service cancels it,
 *    so the child cannot do interactive HITL.
 * 4. **Foreground block** — The current subagent tool blocks the parent LLM
 *    turn until the child completes. There is no non-blocking fork/background
 *    mode that would allow the TUI to show live child progress.
 *
 * These blockers will be resolved by the future async fork/subagent queue
 * (dedicated Messenger worker) which will expose child RunHandle lifecycle,
 * independent event streaming, and background execution.
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

                // ── Subcommand detection ──
                if ('list' === $args || '' === $args) {
                    return $this->listTabs();
                }

                // ── Numeric tab index switching ──
                $index = (int) $args;

                if ((string) $index !== $args) {
                    return new TranscriptMessage(
                        \sprintf(
                            'Unknown tab command: "%s". Use "/tab list" or "/tab <index>"',
                            $args,
                        ),
                        'system',
                        'warning',
                    );
                }

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

        // Register idempotently
        if ($this->commandRegistry->has('tab')) {
            $this->commandRegistry->setHandler('tab', $handler);
        } else {
            $this->commandRegistry->register(
                new CommandMetadata(
                    name: 'tab',
                    aliases: ['t'],
                    description: 'Switch to a tab by index or list available tabs',
                    usage: '/tab [list|<index>]',
                    acceptsArguments: true,
                ),
                $handler,
            );
        }
    }
}
