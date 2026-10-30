<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationReplyPublisher;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationReplyDTO;
use Ineersa\CodingAgent\Application\Message\DTO\RecordChildLaunchFailureDTO;
use Ineersa\CodingAgent\Application\Message\DTO\RecordChildLaunchSuccessDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class ChildInvocationLifecycleHandler
{
    public function __construct(private ChildInvocationLifecycleService $lifecycle, private BashRegistrationReplyPublisher $replies, private ValidatorInterface $validator, private string $sessionId)
    {
    }

    public function __invoke(RecordChildLaunchSuccessDTO|RecordChildLaunchFailureDTO $command): void
    {
        $errors = $this->validator->validate($command);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($command, $errors);
        }
        if ($command instanceof RecordChildLaunchSuccessDTO) {
            $this->lifecycle->applyLaunchSuccessState($command->parentRunId, $command->parentToolCallId, $command->lifecycleId, $command->startedAt);
        } else {
            $this->lifecycle->applyLaunchFailurePreparation($command->parentRunId, $command->parentToolCallId, $command->lifecycleId);
        }
        // Only committed metadata is replayed. The requester owns start/followUp.
        $this->replies->publish(new BashRegistrationReplyDTO($this->sessionId, $command->incarnation, $command->correlation, role: $command->role, deferredId: $command->lifecycleId));
    }
}
