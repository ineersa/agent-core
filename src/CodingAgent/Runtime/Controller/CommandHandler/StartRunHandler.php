<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\ForkRunTerminalWatcher;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles start_run commands via Symfony EventDispatcher.
 *
 * Dispatches a start_run command to the run_control transport and immediately
 * returns to the event loop. Runtime events from the consumer process are
 * forwarded to TUI via the controller's periodic EventStore drain and LLM
 * consumer stdout streaming.
 *
 * When fork_mode is detected in options, starts a ForkRunTerminalWatcher that
 * polls for terminal run state and performs handoff validation + artifact
 * writing.  This is the controller-side finalization path for fork children;
 * the TUI-side ForkAutoExitRegistrar only needs to see the terminal run event
 * and stop the event loop.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class StartRunHandler
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
        private readonly ?ForkRunTerminalWatcher $forkTerminalWatcher = null,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if ('start_run' !== $event->command->type) {
            return;
        }

        $command = $event->command;
        $prompt = (string) ($command->payload['prompt'] ?? '');
        $model = isset($command->payload['model']) ? (string) $command->payload['model'] : null;
        $reasoning = isset($command->payload['reasoning']) ? (string) $command->payload['reasoning'] : null;
        $cwd = isset($command->payload['cwd']) ? (string) $command->payload['cwd'] : '';
        $commandRunId = $command->runId ?? '';
        $sessionRunId = 'unknown' !== $event->sessionId ? $event->sessionId : '';
        $runId = '' !== $commandRunId ? $commandRunId : $sessionRunId;
        $options = isset($command->payload['options']) && \is_array($command->payload['options'])
            ? $command->payload['options']
            : [];

        // Non-blocking: dispatches StartRun to run_control transport and returns
        // immediately. The run_control consumer picks up the message and processes
        // the run asynchronously. Events flow back through:
        //   1. EventStore (committed by consumer) → controller event drain → TUI
        //   2. LLM consumer stdout (streaming deltas) → controller poll → TUI
        $handle = $this->client->start(new StartRunRequest(
            prompt: $prompt,
            runId: $runId,
            cwd: $cwd,
            options: $options,
            model: '' !== $model ? $model : null,
            reasoning: '' !== $reasoning ? $reasoning : null,
        ));

        $event->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunStarted->value,
            runId: $handle->runId,
            seq: 0,
            payload: ['status' => 'running'],
        ));

        // ── Fork finalization watcher (controller-side) ──
        // When fork_mode is set, the terminal watcher polls RunStore for
        // terminal state, validates the handoff, and writes result artifacts.
        // The TUI-side ForkAutoExitRegistrar simply stops the event loop on
        // terminal; all AppAgent-intensive work lives here in the controller.
        if (true === ($options['fork_mode'] ?? false) && null !== $this->forkTerminalWatcher) {
            $this->forkTerminalWatcher->startForForkRun(
                runId: $handle->runId,
                forkOptions: $options,
            );
        }

        // Events are NOT iterated here — they arrive through the controller's
        // periodic EventStore drain (canonical seq > 0) and LLM consumer
        // stdout (transient seq = 0 streaming deltas).
    }
}
