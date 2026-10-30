<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessFinishedDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class BashProcessFinishedHandler
{
    public function __construct(
        private ProcessStore $store,
        private RunLockManager $locks,
        private ValidatorInterface $validator,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(BashProcessFinishedDTO $event): void
    {
        $errors = $this->validator->validate($event);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($event, $errors);
        }
        $this->locks->synchronized($event->runId, function () use ($event): void {
            if (null === $this->store->recordIdForStatusPath($event->statusPath, $event->runId)) {
                // Crash windows can lose Started. Do not invent a process row.
                $this->logger->warning('bash_lifecycle.finished_without_started', [
                    'component' => 'bash.lifecycle',
                    'event_type' => 'bash_lifecycle.finished_without_started',
                    'run_id' => $event->runId,
                    'session_id' => $event->runId,
                ]);

                return;
            }
            $this->store->applyFinished($event);
        });
    }
}
