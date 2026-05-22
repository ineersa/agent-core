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
 * Handles user_message commands via Symfony EventDispatcher.
 *
 * Sends a user message for the current run and forwards all resulting
 * runtime events (steer/follow-up response).
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
        if ('user_message' !== $event->command->type) {
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

        $this->client->send($runId, new UserCommand(
            type: 'message',
            text: (string) ($command->payload['text'] ?? ''),
        ));

        // Forward all events from the follow-up steer/response.
        foreach ($this->client->events($runId) as $runtimeEvent) {
            $event->emit($runtimeEvent);
        }
    }
}
