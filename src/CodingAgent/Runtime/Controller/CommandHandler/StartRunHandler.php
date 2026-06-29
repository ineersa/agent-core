<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Controller\ForkControllerStartService;
use Ineersa\CodingAgent\Runtime\Controller\ForkRunTerminalWatcher;
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
 * Normal (non-fork) starts use InProcessAgentSessionClient directly.
 * Fork mode starts use ForkControllerStartService which loads the fork snapshot,
 * builds fresh child-cwd messages, and composes them with the fork task prompt.
 *
 * When fork_mode is detected in options, also starts a ForkRunTerminalWatcher
 * that polls for terminal run state and performs handoff validation + artifact
 * writing in the controller process.  The TUI-side ForkAutoExitRegistrar only
 * needs to see the terminal run event and stop the event loop.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class StartRunHandler
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
        private readonly ?ForkControllerStartService $forkStartService = null,
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

        // ── Fork mode start (controller-side bootstrap) ──
        // Detected via scalar fork_mode option from process transport.
        // ForkControllerStartService loads the snapshot, builds fresh
        // child-cwd messages, and starts the run in the controller process.
        // Normal (non-fork) start uses InProcessAgentSessionClient as before.
        // Both paths are non-blocking.  Events flow back through:
        //   1. EventStore (committed by consumer) → controller event drain → TUI
        //   2. LLM consumer stdout (streaming deltas) → controller poll → TUI
        //
        // CRITICAL: If fork_mode is set but ForkControllerStartService is null,
        // fail immediately with a clear message rather than silently falling
        // through to InProcessAgentSessionClient (which would start a bogus
        // empty run with no fork seed messages).
        if (true === ($options['fork_mode'] ?? false)) {
            if (null === $this->forkStartService) {
                throw new \RuntimeException('Fork mode requires ForkControllerStartService but it is not wired. Check DI container configuration for fork support.');
            }

            $handle = $this->forkStartService->start($options);
        } else {
            $handle = $this->client->start(new StartRunRequest(
                prompt: $prompt,
                runId: $runId,
                cwd: $cwd,
                options: $options,
                model: '' !== $model ? $model : null,
                reasoning: '' !== $reasoning ? $reasoning : null,
            ));
        }

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
        //
        // CRITICAL: If fork_mode is set but ForkRunTerminalWatcher is null,
        // fail immediately rather than silently skipping result finalization.
        if (true === ($options['fork_mode'] ?? false)) {
            if (null === $this->forkTerminalWatcher) {
                throw new \RuntimeException('Fork mode requires ForkRunTerminalWatcher but it is not wired. Check DI container configuration for fork finalization support.');
            }

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
