<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationReplyPublisher;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationReplyDTO;
use Ineersa\CodingAgent\Application\Message\DTO\RegisterDeferredInvocationDTO;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class RegisterDeferredInvocationHandler
{
    public function __construct(private DeferredToolCompletionRepository $deferred, private BashRegistrationReplyPublisher $replies, private ValidatorInterface $validator, private string $sessionId)
    {
    }

    public function __invoke(RegisterDeferredInvocationDTO $command): void
    {
        $errors = $this->validator->validate($command);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($command, $errors);
        }
        $registered = $this->deferred->registerPending($command->invocation);
        $this->replies->publish(new BashRegistrationReplyDTO($this->sessionId, $command->incarnation, $command->correlation, role: $command->role, deferredId: $registered->deferredId));
    }
}
