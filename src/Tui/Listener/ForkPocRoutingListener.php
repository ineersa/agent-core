<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Runtime\ForkPocPendingStore;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TabDefinition;
use Ineersa\Tui\Runtime\TabInputModeEnum;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionState;

/**
 * POC: Registers the /fork-poc slash command for interactive fork-like tabs.
 *
 * Usage:
 *   /fork-poc <task>    — Start a fork child run with the given task prompt
 *
 * Creates a placeholder tab immediately and enqueues the blocking start()
 * call to the ForkPocPendingStore. The actual AgentSessionClient::start()
 * runs on a later tick after the placeholder tab has been rendered.
 *
 * This proves the interactive routing layer required for true fork UX.
 */
final class ForkPocRoutingListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly SlashCommandRegistry $commandRegistry,
        private readonly ForkPocPendingStore $pendingStore,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $pendingStore = $this->pendingStore;

        $handler = new class($pendingStore, $context) implements SlashCommandHandler {
            public function __construct(
                private readonly ForkPocPendingStore $pendingStore,
                private readonly TuiRuntimeContext $context,
            ) {
            }

            public function handle(\Ineersa\Tui\Command\SlashCommand $command): \Ineersa\Tui\Command\CommandResult
            {
                $task = trim($command->args);

                if ('' === $task) {
                    return new TranscriptMessage(
                        'Usage: /fork-poc <task>. Start an interactive fork child run.',
                        'system',
                        'warning',
                    );
                }

                $tabService = $this->context->tabService;
                $screen = $this->context->screen;

                if (null === $tabService) {
                    return new TranscriptMessage(
                        'Tab service not available. Cannot create fork tab.',
                        'system',
                        'error',
                    );
                }

                $parentTab = $tabService->tabAt(0);
                if (null === $parentTab) {
                    return new TranscriptMessage(
                        'Parent tab not found. Cannot create fork child.',
                        'system',
                        'error',
                    );
                }

                $parentRunId = $parentTab->runId;

                // Create placeholder tab immediately. The actual blocking
                // start() call happens on a later tick, after this tab has
                // been rendered to the terminal at least once.
                $placeholderId = 'fork-poc-starting-'.md5($task.microtime());

                $childState = new TuiSessionState(
                    sessionId: $placeholderId,
                );
                $childState->activity = RunActivityStateEnum::Starting;
                $childState->cwd = $this->context->state->cwd;

                $childState->transcript[] = new TranscriptBlock(
                    id: 'fork_poc_queued',
                    kind: TranscriptBlockKindEnum::System,
                    runId: $placeholderId,
                    seq: 0,
                    text: "\u{25d0} ForkPOC queued \u2014 starting on next tick\u2026",
                );

                $placeholderRunId = 'fork-poc-'.$placeholderId;

                $tabService->addTab(new TabDefinition(
                    id: $placeholderRunId,
                    label: "Fork \u{25b6}",
                    runId: $placeholderId,
                    state: $childState,
                    inputMode: TabInputModeEnum::Interactive,
                ));

                // Auto-switch to the new placeholder tab
                $newIndex = $tabService->count() - 1;
                $tabService->switchTo($newIndex);
                $screen->setTranscriptBlocks($childState->transcript);
                $screen->setWorkingMessage('Queuing fork...');

                // Append confirmation to parent transcript
                $parentState = $this->context->state;
                $parentState->transcript[] = new TranscriptBlock(
                    id: 'fork_poc_queued_parent_'.$placeholderId,
                    kind: TranscriptBlockKindEnum::System,
                    runId: $parentRunId,
                    seq: $parentState->lastSeq + 1,
                    text: \sprintf(
                        "\u{25d0} ForkPOC queued [task: %s] \u2014 see /tab %d.",
                        mb_substr($task, 0, 60),
                        $newIndex + 1,
                    ),
                );
                ++$parentState->lastSeq;

                // Enqueue pending start (deferred to later tick)
                $this->pendingStore->enqueue(
                    placeholderRunId: $placeholderRunId,
                    placeholderId: $placeholderId,
                    task: $task,
                    cwd: $this->context->state->cwd,
                );

                return new NoOp();
            }
        };

        // Register /fork-poc command idempotently
        if ($this->commandRegistry->has('fork-poc')) {
            $this->commandRegistry->setHandler('fork-poc', $handler);
        } else {
            $this->commandRegistry->register(
                new CommandMetadata(
                    name: 'fork-poc',
                    description: 'POC: start an interactive fork child run to prove steering/cancel routing',
                    usage: '/fork-poc <task>',
                    acceptsArguments: true,
                ),
                $handler,
            );
        }
    }
}
