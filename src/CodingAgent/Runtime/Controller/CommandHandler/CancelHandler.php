<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles cancel commands via Symfony EventDispatcher.
 *
 * Requests cancellation of the current agent run via the in-process client.
 * Foreground tool processes owned by the tool worker will detect the
 * cancellation token on their next poll and terminate themselves.
 *
 * No cross-process PID registry or process-kill handoff is needed — the
 * tool worker that started each subprocess is responsible for stopping it.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class CancelHandler
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
        private readonly LoggerInterface $logger = new NullLogger(),
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

        $this->logger->info('Handling cancel command', ['runId' => $runId]);

        // Request asynchronous cancellation through AgentCore.
        $this->client->cancel($runId);

        // Do NOT emit a synthetic RunCancelled here.  The real RunCancelled
        // event is produced by AgentCore when cancellation completes
        // (LlmStepResultHandler or AdvanceRunHandler), and arriving
        // prematurely can make the controller think the run is Cancelled
        // before AgentCore has left Cancelling — causing follow-up commands
        // to be rejected.

        $this->logger->info('Cancel dispatched to AgentCore', ['runId' => $runId]);
    }
}
