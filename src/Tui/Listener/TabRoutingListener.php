<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\TabSwitchCommand;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TabDefinition;
use Ineersa\Tui\Runtime\TabService;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Transcript\TranscriptBlockFactory;

/**
 * POC: Registers the /tab slash command and applies tab switching.
 *
 * The /tab command lets the user switch between available tabs
 * and create new child-run tabs backed by real agent sessions:
 *
 *   /tab 2              — switch to tab 2 (1-indexed)
 *   /tab list           — show available tabs
 *   /tab start "prompt" — create a new child run in a new tab
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
        private readonly AgentSessionClient $client,
        private readonly TranscriptBlockFactory $blockFactory,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $tabService = $context->tabService;
        $screen = $context->screen;
        $client = $this->client;
        $blockFactory = $this->blockFactory;
        $sessionStore = $context->sessionStore;

        $handler = new class($tabService, $screen, $client, $blockFactory, $sessionStore) implements SlashCommandHandler {
            public function __construct(
                private readonly TabService $tabService,
                private readonly \Ineersa\Tui\Screen\ChatScreen $screen,
                private readonly AgentSessionClient $client,
                private readonly TranscriptBlockFactory $blockFactory,
                private readonly HatfieldSessionStore $sessionStore,
            ) {
            }

            public function handle(SlashCommand $command): \Ineersa\Tui\Command\CommandResult
            {
                $args = trim($command->args);

                // ── Subcommand detection ──
                if ('list' === $args || '' === $args) {
                    return $this->listTabs();
                }

                $parts = explode(' ', $args, 2);
                $first = $parts[0] ?? '';

                if ('start' === $first) {
                    $prompt = $parts[1] ?? '';

                    return $this->startChildRun($prompt);
                }

                // ── Numeric tab index switching ──
                $index = (int) $args;

                if ((string) $index !== $args) {
                    return new TranscriptMessage(
                        \sprintf(
                            'Unknown tab command: "%s". Use "/tab list", "/tab <index>", or "/tab start \"<prompt>\""',
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

            /**
             * Create a new child run backed by a real agent session.
             *
             * This is the core POC demonstration: a second agent session
             * managed as a separate TUI tab with its own event polling,
             * submit/cancel routing, and transcript state.
             *
             * Flow:
             * 1. Create a session via HatfieldSessionStore
             * 2. Create TuiSessionState for the new run
             * 3. Call $client->start() to launch the run
             * 4. Register the new run as a tab definition
             * 5. Switch to the new tab
             */
            private function startChildRun(string $prompt): \Ineersa\Tui\Command\CommandResult
            {
                $prompt = trim($prompt);

                // Strip surrounding quotes if present
                if (\strlen($prompt) >= 2) {
                    $first = $prompt[0];
                    $last = $prompt[-1];
                    if (('"' === $first && '"' === $last) || ("'" === $first && "'" === $last)) {
                        $prompt = substr($prompt, 1, -1);
                    }
                }

                if ('' === $prompt) {
                    return new TranscriptMessage(
                        'Usage: /tab start "<prompt>" - Start a new child run in a new tab.',
                        'system',
                        'warning',
                    );
                }

                try {
                    // 1. Create a fresh session for the child run
                    $childSessionId = $this->sessionStore->createSession($prompt);

                    // 2. Create TuiSessionState for the new run
                    $childState = new TuiSessionState($childSessionId, false);
                    $childState->transcript = [
                        $this->blockFactory->system(
                            runId: $childSessionId,
                            text: \sprintf(
                                'Child run created. Prompt: "%s"',
                                $prompt,
                            ),
                            seq: 1,
                        ),
                    ];

                    // 3. Start the run — get a real handle for event polling
                    $request = new StartRunRequest(
                        prompt: $prompt,
                        runId: $childSessionId,
                    );
                    $handle = $this->client->start($request);
                    $childState->handle = $handle;
                    $childState->activity = RunActivityStateEnum::Starting;

                    // 4. Register as a new tab
                    $tabLabel = \sprintf('Child %s', substr($childSessionId, 0, 8));
                    $this->tabService->addTab(new TabDefinition(
                        id: 'child-'.$childSessionId,
                        label: $tabLabel,
                        runId: $childSessionId,
                        state: $childState,
                        isRun: true,
                    ));

                    // 5. Switch to the new tab
                    $newIndex = $this->tabService->count() - 1;
                    $this->tabService->switchTo($newIndex);
                    $this->screen->setTranscriptBlocks($childState->transcript);

                    return new TranscriptMessage(
                        \sprintf(
                            'Started child run %s in tab %d. Prompt: "%s"',
                            $childSessionId,
                            $newIndex + 1,
                            $prompt,
                        ),
                        'system',
                    );
                } catch (\Throwable $e) {
                    return new TranscriptMessage(
                        \sprintf(
                            'Failed to start child run: %s',
                            $e->getMessage(),
                        ),
                        'system',
                        'error',
                    );
                }
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

                $lines[] = '';
                $lines[] = 'Create a new child run tab: /tab start "<prompt>"';

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
                    description: 'Switch to a tab by index, list tabs, or start a child run',
                    usage: '/tab [list|<index>|start "<prompt>"]',
                    acceptsArguments: true,
                ),
                $handler,
            );
        }
    }
}
