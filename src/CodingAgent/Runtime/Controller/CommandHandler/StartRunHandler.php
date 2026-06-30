<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Controller\ForkControllerStartService;
use Ineersa\CodingAgent\Runtime\Controller\ForkRunFinalizer;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
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
 * When fork_mode is detected in options, registers a terminal-event callback on
 * RuntimeEventEmitter that triggers ForkRunFinalizer.  Finalization (handoff
 * validation, repair, artifact writing, .fork-finalized marker) runs inside the
 * fork-mode controller process when the canonical event drain forwards a
 * terminal runtime event (run.completed, run.failed, run.cancelled).  The
 * TUI-side ForkAutoExitRegistrar waits for .fork-finalized before exiting.
 *
 * No separate EventLoop polling watcher is used — finalization is event-driven
 * through the existing event drain pipeline.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class StartRunHandler
{
    /**
     * Terminal runtime event types that trigger fork finalization.
     *
     * @var list<string>
     */
    private const array FORK_TERMINAL_EVENT_TYPES = [
        RuntimeEventTypeEnum::RunCompleted->value,
        RuntimeEventTypeEnum::RunFailed->value,
        RuntimeEventTypeEnum::RunCancelled->value,
    ];

    public function __construct(
        private readonly InProcessAgentSessionClient $client,
        private readonly RuntimeEventEmitter $emitter,
        private readonly ?ForkControllerStartService $forkStartService = null,
        private readonly ?ForkRunFinalizer $forkRunFinalizer = null,
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
        // CRITICAL: Preflight BOTH fork services before any start side effects.
        // If fork_mode is set but ForkControllerStartService is null, fail
        // immediately rather than silently falling through to
        // InProcessAgentSessionClient (which would start a bogus empty run with
        // no fork seed messages).  If ForkRunFinalizer is null, fail immediately
        // rather than starting a fork child that will never be finalized
        // (orphaned run).
        if (true === ($options['fork_mode'] ?? false)) {
            if (null === $this->forkStartService) {
                throw new \RuntimeException('Fork mode requires ForkControllerStartService but it is not wired. Check DI container configuration for fork support.');
            }
            if (null === $this->forkRunFinalizer) {
                throw new \RuntimeException('Fork mode requires ForkRunFinalizer but it is not wired. Check DI container configuration for fork finalization support.');
            }

            $handle = $this->forkStartService->start($options);

            // Register terminal-event callback on the emitter instead of
            // starting a separate polling watcher.  The emitter's canonical
            // event drain fires this callback when it forwards a terminal
            // runtime event (run.completed/failed/cancelled) for this run.
            // ForkRunFinalizer is idempotent after full finalization.
            $finalizer = $this->forkRunFinalizer;
            $forkOptions = $options;
            $this->emitter->onRunEvent(
                runId: $handle->runId,
                eventTypes: self::FORK_TERMINAL_EVENT_TYPES,
                callback: static function (RuntimeEvent $runtimeEvent) use ($finalizer, $handle, $forkOptions): void {
                    $finalizer->finalize($handle->runId, $forkOptions);
                },
            );
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

        // Events are NOT iterated here — they arrive through the controller's
        // periodic EventStore drain (canonical seq > 0) and LLM consumer
        // stdout (transient seq = 0 streaming deltas).
    }
}
