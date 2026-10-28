<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashOwnershipCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationReplyDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class ChangeBashOwnershipHandler
{
    public function __construct(
        private BashOwnershipService $ownership,
        private BashRegistrationReplyPublisher $replies,
        private RunLockManager $locks,
        private ValidatorInterface $validator,
        private string $sessionId,
    ) {
    }

    public function __invoke(BashOwnershipCommandDTO $command): void
    {
        $errors = $this->validator->validate($command);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($command, $errors);
        }
        $status = BashOwnershipActionEnum::Accept === $command->action
            ? $this->ownership->accept($command->request)
            : $this->ownership->retire($command->request);
        $reply = new BashRegistrationReplyDTO($this->sessionId, $command->incarnation, $command->correlation, ownership: $status, role: $command->role);
        $this->locks->afterRelease($command->request->ownerSessionId, fn () => $this->replies->publish($reply));
    }
}
