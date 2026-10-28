<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationReplyDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessCommandDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class RegisterBashProcessHandler
{
    public function __construct(
        private BashRegistrationService $registration,
        private BashRegistrationReplyPublisher $replies,
        private RunLockManager $locks,
        private ValidatorInterface $validator,
        private string $sessionId,
    ) {
    }

    public function __invoke(RegisterBashProcessCommandDTO $command): void
    {
        $errors = $this->validator->validate($command);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($command, $errors);
        }
        // This is metadata, not a RunState command: it deliberately does not
        // initialize/replay a run. The operation owns its transaction and lock.
        $result = $this->registration->register($command->registration);
        $reply = new BashRegistrationReplyDTO($this->sessionId, $command->incarnation, $command->correlation, $result);
        $this->locks->afterRelease($command->registration->ownerSessionId, fn () => $this->replies->publish($reply));
    }
}
