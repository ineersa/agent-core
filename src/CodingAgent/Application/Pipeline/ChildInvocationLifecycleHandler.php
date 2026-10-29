<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Pipeline;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Application\BackgroundProcess\BashRegistrationReplyPublisher;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationReplyDTO;
use Ineersa\CodingAgent\Application\Message\DTO\RecordChildLaunchFailureDTO;
use Ineersa\CodingAgent\Application\Message\DTO\RecordChildLaunchSuccessDTO;
use Ineersa\CodingAgent\Application\Message\DTO\ReserveChildInvocationDTO;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[AsMessageHandler(bus: 'agent.command.bus')]
final readonly class ChildInvocationLifecycleHandler
{
    public function __construct(private ChildInvocationLifecycleService $lifecycle, private ChildReservationRefusalService $refusals, private BashRegistrationReplyPublisher $replies, private ValidatorInterface $validator, private string $sessionId)
    {
    }

    public function __invoke(ReserveChildInvocationDTO|RecordChildLaunchSuccessDTO|RecordChildLaunchFailureDTO $command): void
    {
        $errors = $this->validator->validate($command);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($command, $errors);
        }
        $batch = null;
        if ($command instanceof ReserveChildInvocationDTO) {
            $missingOwner = $this->refusals->missingOwnerRefusal($command);
            if (null !== $missingOwner) {
                // Permanent deletion removes retained refusals and the session.
                // Refuse without inserting a batch/child under SQLite's disabled FKs.
                $this->replies->publish(new BashRegistrationReplyDTO($this->sessionId, $command->incarnation, $command->correlation, role: $command->role, childReservationRefusal: $missingOwner));

                return;
            }
            $retained = $this->refusals->retainedRefusal($command);
            if (null !== $retained) {
                $this->replies->publish(new BashRegistrationReplyDTO($this->sessionId, $command->incarnation, $command->correlation, role: $command->role, childReservationRefusal: $retained));

                return;
            }
            try {
                $batch = $this->lifecycle->reserveBatch($command->lifecycleId, $command->parentRunId, $command->parentTurnNo, $command->parentToolCallId, $command->parentOrderIndex, $command->deadlineAt, $command->childIntent, $command->parentModel);
            } catch (ToolCallException $refusal) {
                // Propagate an authoritative refusal, not an unknown transport
                // outcome that would retry an ineligible reservation.
                // Retain the terminal refusal before publication so a lost ACK
                // cannot later accept the same rejected invocation.
                $message = $this->refusals->retain($command, $refusal->getMessage());
                $this->replies->publish(new BashRegistrationReplyDTO($this->sessionId, $command->incarnation, $command->correlation, role: $command->role, childReservationRefusal: $message));

                return;
            }
        } elseif ($command instanceof RecordChildLaunchSuccessDTO) {
            $this->lifecycle->applyLaunchSuccessState($command->parentRunId, $command->parentToolCallId, $command->lifecycleId, $command->startedAt);
        } else {
            $this->lifecycle->applyLaunchFailurePreparation($command->parentRunId, $command->parentToolCallId, $command->lifecycleId);
        }
        // Only committed metadata is replayed. The requester owns start/followUp.
        $this->replies->publish(new BashRegistrationReplyDTO($this->sessionId, $command->incarnation, $command->correlation, role: $command->role, deferredId: $command->lifecycleId, childBatch: $batch));
    }
}
