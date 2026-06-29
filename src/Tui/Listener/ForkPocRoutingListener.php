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
use Psr\Log\LoggerInterface;

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
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $pendingStore = $this->pendingStore;

        $logger = $this->logger;

        $handler = new class($pendingStore, $context, $logger) implements SlashCommandHandler {
            public function __construct(
                private readonly ForkPocPendingStore $pendingStore,
                private readonly TuiRuntimeContext $context,
                private readonly LoggerInterface $logger,
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

                $this->logger->info('ForkPOC handler invoked', [
                    'task' => mb_substr($task, 0, 120),
                ]);

                $tabService = $this->context->tabService;
                $screen = $this->context->screen;

                if (null === $tabService) {
                    $this->logger->warning('ForkPOC: tabService is null');

                    return new TranscriptMessage(
                        'ForkPOC ERROR: Tab service not available. Cannot create fork tab.',
                        'system',
                        'error',
                    );
                }

                $parentTab = $tabService->tabAt(0);
                if (null === $parentTab) {
                    $this->logger->warning('ForkPOC: parent tab not found');

                    return new TranscriptMessage(
                        'ForkPOC ERROR: Parent tab not found. Cannot create fork child.',
                        'system',
                        'error',
                    );
                }

                $parentRunId = $parentTab->runId;

                // ─── Step 1: Add visible confirmation to PARENT transcript ───
                // This proves the handler was reached, EVEN if tab creation fails.
                $parentState = $this->context->state;
                ++$parentState->lastSeq;
                $parentState->transcript[] = new TranscriptBlock(
                    id: 'fork_poc_received_'.$parentState->lastSeq,
                    kind: TranscriptBlockKindEnum::System,
                    runId: $parentRunId,
                    seq: $parentState->lastSeq,
                    text: \sprintf(
                        "\u{25d0} ForkPOC received [task: %s]",
                        mb_substr($task, 0, 60),
                    ),
                );
                $screen->setTranscriptBlocks($parentState->transcript);

                // ─── Step 2: Create placeholder tab ───
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
                ++$parentState->lastSeq;
                $parentState->transcript[] = new TranscriptBlock(
                    id: 'fork_poc_queued_parent_'.$placeholderId,
                    kind: TranscriptBlockKindEnum::System,
                    runId: $parentRunId,
                    seq: $parentState->lastSeq,
                    text: \sprintf(
                        "\u{25d0} ForkPOC queued [task: %s] \u2014 see /tab %d.",
                        mb_substr($task, 0, 60),
                        $newIndex + 1,
                    ),
                );

                // Enqueue pending start (deferred to later tick)
                $this->pendingStore->enqueue(
                    placeholderRunId: $placeholderRunId,
                    placeholderId: $placeholderId,
                    task: $task,
                    cwd: $this->context->state->cwd,
                );

                $this->logger->info('ForkPOC tab created and enqueued', [
                    'placeholderRunId' => $placeholderRunId,
                    'tabIndex' => $newIndex,
                    'totalTabs' => $tabService->count(),
                ]);

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
