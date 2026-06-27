<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles JSONL resume commands via Symfony EventDispatcher.
 *
 * Passive attach only: scopes the controller client to the run and emits
 * run.resumed. Does not dispatch AgentCore Continue.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class ResumeHandler
{
    public function __construct(
        private readonly AgentSessionClient $client,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if ('resume' !== $event->command->type) {
            return;
        }

        $command = $event->command;
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            $event->emit(new RuntimeEvent(
                type: RuntimeEventTypeEnum::ProtocolError->value,
                runId: '',
                seq: 0,
                payload: ['error' => 'resume requires runId'],
            ));

            return;
        }

        $handle = $this->client->attach($runId);

        $event->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunResumed->value,
            runId: $handle->runId,
            seq: 1,
            payload: ['status' => $handle->status],
        ));

        // Events are NOT iterated here — they arrive through the controller's
        // periodic EventStore drain and publish transport poller (ASYNC-05).
    }
}
