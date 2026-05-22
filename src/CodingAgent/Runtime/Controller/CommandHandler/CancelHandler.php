<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles cancel commands via Symfony EventDispatcher.
 *
 * Requests cancellation of the current agent run via the in-process client.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class CancelHandler
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if ('cancel' !== $event->command->type) {
            return;
        }

        $command = $event->command;
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            return;
        }

        $this->client->cancel($runId);

        $event->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunCancelled->value,
            runId: $runId,
            seq: 0,
        ));
    }
}
