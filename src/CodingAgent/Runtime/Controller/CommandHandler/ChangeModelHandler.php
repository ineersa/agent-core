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
 * Handles change_model JSONL commands from the parent TUI process.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class ChangeModelHandler
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if ('change_model' !== $event->command->type) {
            return;
        }

        $command = $event->command;
        $runId = trim($command->runId ?? '');
        if ('' === $runId) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: '',
                seq: 0,
                payload: ['error' => 'change_model requires runId'],
            ));

            return;
        }

        $model = trim((string) ($command->payload['model'] ?? ''));
        if ('' === $model) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: $runId,
                seq: 0,
                payload: ['error' => 'change_model requires non-empty model'],
            ));

            return;
        }

        $this->client->send($runId, new UserCommand(
            type: 'change_model',
            text: null,
            payload: ['model' => $model],
        ));

        $event->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::CommandAck->value,
            runId: $runId,
            seq: 0,
            payload: ['type' => 'change_model', 'model' => $model],
        ));
    }
}
