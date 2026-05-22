<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles start_run commands via Symfony EventDispatcher.
 *
 * Dispatches a start_run command to the run_control transport (ASYNC-05)
 * and immediately returns to the event loop. Runtime events from the
 * consumer process are forwarded to TUI via the controller's periodic
 * EventStore drain timer.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class StartRunHandler
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
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

        // Non-blocking: dispatches StartRun to run_control transport and returns
        // immediately. The run_control consumer picks up the message and processes
        // the run asynchronously. Events flow back through:
        //   1. EventStore (committed by consumer) → controller event drain → TUI
        //   2. Publish transport (streaming deltas) → controller poll → TUI
        $handle = $this->client->start(new StartRunRequest(
            prompt: $prompt,
            model: '' !== $model ? $model : null,
            reasoning: '' !== $reasoning ? $reasoning : null,
        ));

        $event->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunStarted->value,
            runId: $handle->runId,
            seq: 1,
            payload: ['status' => 'running'],
        ));

        // Events are NOT iterated here — they arrive through the controller's
        // periodic EventStore drain and publish transport poller (ASYNC-05).
    }
}
