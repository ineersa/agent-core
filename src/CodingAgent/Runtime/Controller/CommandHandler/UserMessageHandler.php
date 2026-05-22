<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;

/**
 * Handles user_message commands.
 *
 * Sends a user message for the current run and forwards all resulting
 * runtime events (steer/follow-up response).
 *
 * @see CommandHandlerInterface
 */
final readonly class UserMessageHandler implements CommandHandlerInterface
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
    ) {
    }

    public function handle(RuntimeCommand $command, callable $emit): void
    {
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            $emit(new RuntimeEvent(
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
        foreach ($this->client->events($runId) as $event) {
            $emit($event);
        }
    }
}
