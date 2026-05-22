<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles resume commands via Symfony EventDispatcher.
 *
 * Dispatches a resume command to the run_control transport (ASYNC-05)
 * and immediately returns to the event loop.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class ResumeHandler
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
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

        // Non-blocking: dispatches ApplyCommand (resume) to run_control
        // transport and returns immediately.
        $handle = $this->client->resume($runId);

        $event->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunResumed->value,
            runId: $handle->runId,
            seq: 1,
            payload: ['status' => 'running'],
        ));

        // Events are NOT iterated here — they arrive through the controller's
        // periodic EventStore drain and publish transport poller (ASYNC-05).
    }
}
