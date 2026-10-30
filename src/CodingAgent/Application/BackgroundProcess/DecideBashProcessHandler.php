<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessCommandDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class DecideBashProcessHandler
{
    public function __construct(
        private ProcessStore $store,
        private ProcessLifecycle $lifecycle,
        private RunLockManager $locks,
        private ValidatorInterface $validator,
    ) {
    }

    public function __invoke(BashProcessCommandDTO $command): void
    {
        $errors = $this->validator->validate($command);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($command, $errors);
        }
        $this->locks->synchronized(
            $command->runId,
            fn () => $this->store->decide($command->recordId, $command->runId, $command->action, $this->lifecycle),
        );
    }
}
