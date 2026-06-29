<?php

declare(strict_types=1);

namespace Ineersa\Tui\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Command\CommandMetadata;
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

                // Create the child run via $client->start()
                $request = new StartRunRequest(
                    prompt: $task,
                    cwd: $this->context->state->cwd,
                );

                $handle = null;
                try {
                    $handle = $this->client->start($request);
                } catch (\Throwable $e) {
                    $this->logger->error('ForkPOC: failed to start child run', [
                        'exception' => $e,
                        'task' => $task,
                    ]);

                    return new TranscriptMessage(
                        'ForkPOC: Failed to start child run: '.$e->getMessage()
                        .' Try running with --transport=in-process for fork POC support.',
                        'system',
                        'error',
                    );
                }

                $childRunId = $handle->runId;

                // Create a TuiSessionState for the child
                $childState = new TuiSessionState(
                    sessionId: $childRunId,
                );
                $childState->handle = $handle;
                $childState->activity = RunActivityStateEnum::Running;
                $childState->cwd = $this->context->state->cwd;

                // Create the interactive tab
                $shortId = substr($childRunId, 0, 8);
                $tabId = 'fork-poc-'.$childRunId;

                $tabService->addTab(new TabDefinition(
                    id: $tabId,
                    label: 'Fork '.$shortId.' ▶',
                    runId: $childRunId,
                    state: $childState,
                    inputMode: TabInputModeEnum::Interactive,
                ));

                // Auto-switch to the fork tab
                $newIndex = $tabService->count() - 1;
                $tabService->switchTo($newIndex);
                $screen->setTranscriptBlocks($childState->transcript);

                // Append a system block to the parent confirming fork start
                $parentState = $this->context->state;
                $parentState->transcript[] = new TranscriptBlock(
                    id: 'fork_poc_start_'.$childRunId,
                    kind: TranscriptBlockKindEnum::System,
                    runId: $parentRunId,
                    seq: $parentState->lastSeq + 1,
                    text: \sprintf(
                        '◐ ForkPOC started [run: %s]. Tab %d active — steer/cancel in fork tab, switch /tab 1 for parent.',
                        $childRunId,
                        $newIndex + 1,
                    ),
                );

                return new TranscriptMessage(
                    \sprintf('ForkPOC: child run %s started. Switched to fork tab (tab %d).', $childRunId, $newIndex + 1),
                    'system',
                );
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
