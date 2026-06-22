<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles user_message, follow_up, and steer commands via Symfony EventDispatcher.
 *
 * Dispatches the message to the run_control transport and immediately returns
 * to the event loop. Events from the consumer process are forwarded to TUI via
 * the controller's periodic EventStore drain and LLM consumer stdout streaming.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class UserMessageHandler
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if (!\in_array($event->command->type, ['user_message', 'follow_up', 'steer'], true)) {
            return;
        }

        $command = $event->command;
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: '',
                seq: 0,
                payload: ['error' => 'user_message requires runId'],
            ));

            return;
        }

        // Non-blocking: dispatches ApplyCommand to run_control transport and
        // returns immediately. The run_control consumer picks it up and
        // processes the message.
        // Map command type to UserCommand type:
        //   steer       -> message (injected while agent is running)
        //   follow_up   -> follow_up (normal next message when idle)
        //   user_message -> message (generic message)
        $commandType = match ($command->type) {
            'follow_up' => 'follow_up',
            'steer' => 'message',
            default => 'message',
        };

        // Re-register the event drain cursor when resuming a completed run.
        // After the shell command's completeAfter AgentEnd removes the cursor
        // (via run.completed), the drain loop stops polling this run's events.
        // Follow-up / steer commands that dispatch AdvanceRun to the run_control
        // consumer produce canonical events (turn_advanced, llm_step_completed,
        // agent_end) that the drain loop would never forward without an active
        // cursor (issue #183).
        $event->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunResumed->value,
            runId: $runId,
            seq: 0,
        ));

        $this->client->send($runId, new UserCommand(
            type: $commandType,
            text: (string) ($command->payload['text'] ?? ''),
        ));

        // Events are NOT iterated here — they arrive through the controller's
        // periodic EventStore drain (canonical seq > 0 events) and LLM
        // consumer stdout (transient seq = 0 streaming deltas).
    }
}
