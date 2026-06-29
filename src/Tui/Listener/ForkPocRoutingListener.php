<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\TranscriptMessage;
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
 * Creates a real sibling run via AgentSessionClient::start() and opens an
 * interactive tab with the child's RunHandle. The user can steer/cancel the
 * child by switching to its tab.
 *
 * This proves the interactive routing layer required for true fork UX.
 */
final class ForkPocRoutingListener implements TuiListenerRegistrar
{
    public function __construct(
        private readonly AgentSessionClient $client,
        private readonly SlashCommandRegistry $commandRegistry,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function register(TuiRuntimeContext $context): void
    {
        $client = $this->client;
        $logger = $this->logger;

        $handler = new class($client, $context, $logger) implements SlashCommandHandler {
            public function __construct(
                private readonly AgentSessionClient $client,
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

                // ── Step 1: Create the tab BEFORE blocking start() ──
                // Give immediate visual feedback: create the tab with a
                // "Starting..." block before the blocking LLM start call.
                // All Messenger transports are sync:// in dev mode, meaning
                // $client->start() blocks for the entire first LLM turn.
                // Showing a tab with status first prevents silent hang.
                $placeholderId = 'fork-poc-starting-'.md5($task.microtime());

                $childState = new TuiSessionState(
                    sessionId: $placeholderId,
                );
                $childState->activity = RunActivityStateEnum::Starting;
                $childState->cwd = $this->context->state->cwd;

                // Add an immediate system block in the child tab
                $childState->transcript[] = new TranscriptBlock(
                    id: 'fork_poc_phase_starting',
                    kind: TranscriptBlockKindEnum::System,
                    runId: $placeholderId,
                    seq: 0,
                    text: '◐ Starting fork child run... (may take a moment on first LLM turn)',
                );

                $placeholderRunId = 'fork-poc-'.$placeholderId;

                $tabService->addTab(new TabDefinition(
                    id: $placeholderRunId,
                    label: 'Fork ▶',
                    runId: $placeholderId,
                    state: $childState,
                    inputMode: TabInputModeEnum::Interactive,
                ));

                $newIndex = $tabService->count() - 1;
                $tabService->switchTo($newIndex);
                $screen->setTranscriptBlocks($childState->transcript);
                $screen->setWorkingMessage('Starting fork...');

                // Append confirmation to parent transcript before blocking call
                $parentState = $this->context->state;
                $parentState->transcript[] = new TranscriptBlock(
                    id: 'fork_poc_queued_'.$placeholderId,
                    kind: TranscriptBlockKindEnum::System,
                    runId: $parentRunId,
                    seq: $parentState->lastSeq + 1,
                    text: \sprintf(
                        '◐ ForkPOC starting [task: %s] — see tab %d.',
                        mb_substr($task, 0, 60),
                        $newIndex + 1,
                    ),
                );
                ++$parentState->lastSeq;

                // ── Step 2: Start the child run (blocks with sync://) ──
                $request = new StartRunRequest(
                    prompt: $task,
                    cwd: $this->context->state->cwd,
                );

                $handle = null;
                try {
                    $handle = $this->client->start($request);
                } catch (\Throwable $e) {
                    // Update the tab to show error state instead of returning
                    // a TranscriptMessage (which would overwrite the screen
                    // and hide the tab we just created).
                    $childState->activity = RunActivityStateEnum::Failed;
                    $childState->transcript[] = new TranscriptBlock(
                        id: 'fork_poc_error',
                        kind: TranscriptBlockKindEnum::System,
                        runId: $placeholderId,
                        seq: 1,
                        text: '✗ ForkPOC failed: '.$e->getMessage()
                            .' Try running with --transport=in-process for fork POC support.',
                    );
                    $screen->setTranscriptBlocks($childState->transcript);
                    $screen->setWorkingMessage('Fork failed');

                    // Update tab label to show failure
                    foreach ($tabService->tabs() as $tab) {
                        if ($tab->id === $placeholderRunId) {
                            $tab->label = 'Fork ✗';
                            break;
                        }
                    }

                    // Update parent transcript with failure notice
                    $parentState->transcript[] = new TranscriptBlock(
                        id: 'fork_poc_failed_'.$placeholderId,
                        kind: TranscriptBlockKindEnum::System,
                        runId: $parentRunId,
                        seq: $parentState->lastSeq + 1,
                        text: '✗ ForkPOC failed: '.$e->getMessage(),
                    );
                    ++$parentState->lastSeq;

                    $this->logger->error('ForkPOC: failed to start child run', [
                        'exception' => $e,
                        'task' => $task,
                    ]);

                    return new NoOp();
                }

                // ── Step 3: Update tab with real run data ──
                $childRunId = $handle->runId;
                $shortId = substr($childRunId, 0, 8);

                // Update the placeholder tab's properties with real data
                foreach ($tabService->tabs() as $tab) {
                    if ($tab->id === $placeholderRunId) {
                        $tab->runId = $childRunId;
                        $tab->label = 'Fork '.$shortId.' ▶';
                        break;
                    }
                }

                $childState->sessionId = $childRunId;
                $childState->handle = $handle;
                $childState->activity = RunActivityStateEnum::Running;

                // Rebuild child transcript — replace "starting" block with running status
                // The TickPollListener will pick up real child events on the next tick.
                $childState->transcript = [];
                $childState->transcript[] = new TranscriptBlock(
                    id: 'fork_poc_running_'.$childRunId,
                    kind: TranscriptBlockKindEnum::System,
                    runId: $childRunId,
                    seq: 0,
                    text: \sprintf(
                        '◐ ForkPOC [%s] running%s — steer/cancel in this tab.',
                        $shortId,
                        \in_array($handle->status, ['completed', 'failed', 'cancelled'], true)
                            ? ', status: '.$handle->status
                            : '',
                    ),
                );

                // Update parent transcript with running confirmation
                $parentState->transcript[] = new TranscriptBlock(
                    id: 'fork_poc_running_'.$childRunId,
                    kind: TranscriptBlockKindEnum::System,
                    runId: $parentRunId,
                    seq: $parentState->lastSeq + 1,
                    text: \sprintf(
                        '◐ ForkPOC started [run: %s] — tab %d active. Switch /tab 1 for parent.',
                        $shortId,
                        $newIndex + 1,
                    ),
                );
                ++$parentState->lastSeq;

                $screen->setTranscriptBlocks($childState->transcript);
                $screen->setWorkingMessage(null);

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
