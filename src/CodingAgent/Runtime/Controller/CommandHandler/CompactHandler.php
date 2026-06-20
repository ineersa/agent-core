<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Handles compact commands via Symfony EventDispatcher.
 */
#[AsEventListener(event: ControllerCommandEvent::class)]
final readonly class CompactHandler
{
    public function __construct(
        private readonly InProcessAgentSessionClient $client,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(ControllerCommandEvent $event): void
    {
        if ('compact' !== $event->command->type) {
            return;
        }

        $command = $event->command;
        $runId = $command->runId ?? '';
        if ('' === $runId) {
            return;
        }

        $customInstructions = null;
        if (isset($command->payload['custom_instructions']) && \is_string($command->payload['custom_instructions'])) {
            $customInstructions = $command->payload['custom_instructions'];
        }

        $this->logger->info('Handling compact command', [
            'runId' => $runId,
            'has_custom_instructions' => null !== $customInstructions,
        ]);

        $this->client->compact($runId, $customInstructions);

        $this->logger->info('Compact dispatched to AgentCore', ['runId' => $runId]);
    }
}
