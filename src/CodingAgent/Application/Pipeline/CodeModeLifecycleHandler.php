<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationReplyPublisher;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationReplyDTO;
use Ineersa\CodingAgent\Application\Message\DTO\ReadyCodeModeInvocationDTO;
use Ineersa\CodingAgent\Application\Message\DTO\RegisterCodeModeInvocationDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class CodeModeLifecycleHandler
{
    public function __construct(private CodeModeLifecycleService $lifecycle, private BashRegistrationReplyPublisher $replies, private ValidatorInterface $validator, private string $sessionId)
    {
    }

    public function __invoke(RegisterCodeModeInvocationDTO|ReadyCodeModeInvocationDTO $command): void
    {
        $errors = $this->validator->validate($command);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($command, $errors);
        }
        if ($command instanceof RegisterCodeModeInvocationDTO) {
            $id = $this->lifecycle->register($command->invocation);
        } else {
            $this->lifecycle->ready($command->runId, $command->deferredId);
            $id = $command->deferredId;
        }
        // The repository releases its run lock before publication. Lost replies
        // redrive only registration/readiness, never the isolated script launch.
        $this->replies->publish(new BashRegistrationReplyDTO($this->sessionId, $command->incarnation, $command->correlation, role: $command->role, deferredId: $id));
    }
}
