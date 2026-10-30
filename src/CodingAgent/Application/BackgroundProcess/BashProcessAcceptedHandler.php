<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessAcceptedDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class BashProcessAcceptedHandler
{
    public function __construct(
        private ProcessStore $store,
        private RunLockManager $locks,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(BashProcessAcceptedDTO $event): void
    {
        $errors = $this->validator->validate($event);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($event, $errors);
        }
        $this->locks->synchronized($event->runId, fn () => $this->store->applyAccepted($event));
    }
}
