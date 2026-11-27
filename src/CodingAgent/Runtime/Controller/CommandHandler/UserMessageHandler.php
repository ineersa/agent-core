<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Contract\UserCommandTypeEnum;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles user_message, follow_up, append_message, and steer commands via Symfony EventDispatcher.
 *
 * Dispatches the message to the run_control transport and immediately returns
 * to the event loop. Events from the consumer process are forwarded to TUI via
 * the controller's periodic EventStore drain and LLM consumer stdout streaming.
 */
#[AsEventListener(event: ControllerCommandEvent::NAME_PREFIX.'user_message')]
#[AsEventListener(event: ControllerCommandEvent::NAME_PREFIX.UserCommandTypeEnum::FollowUp->value)]
#[AsEventListener(event: ControllerCommandEvent::NAME_PREFIX.UserCommandTypeEnum::AppendMessage->value)]
#[AsEventListener(event: ControllerCommandEvent::NAME_PREFIX.UserCommandTypeEnum::Steer->value)]
final readonly class UserMessageHandler
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
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
            UserCommandTypeEnum::FollowUp->value => UserCommandTypeEnum::FollowUp,
            UserCommandTypeEnum::AppendMessage->value => UserCommandTypeEnum::AppendMessage,
            UserCommandTypeEnum::Steer->value => UserCommandTypeEnum::Message,
            default => UserCommandTypeEnum::Message,
        };

        $this->client->send($runId, new UserCommand(
            type: $commandType,
            text: (string) ($command->payload['text'] ?? ''),
        ));

        // Events are NOT iterated here — they arrive through the controller's
        // periodic EventStore drain (canonical seq > 0 events) and LLM
        // consumer stdout (transient seq = 0 streaming deltas).
    }
}
