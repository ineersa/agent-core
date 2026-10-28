<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundProcess;

use Amp\Cancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Launch\DeferredSubagentBatchChildIntentDTO;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Projection\DeferredSubagentBatchProjectionDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashOwnershipCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashOwnershipRequestDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashProcessSnapshotDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationReplyDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\BashRegistrationResultDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessCommandDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RegisterBashProcessDTO;
use Ineersa\CodingAgent\Application\Message\DTO\ReadyCodeModeInvocationDTO;
use Ineersa\CodingAgent\Application\Message\DTO\RecordChildLaunchFailureDTO;
use Ineersa\CodingAgent\Application\Message\DTO\RecordChildLaunchSuccessDTO;
use Ineersa\CodingAgent\Application\Message\DTO\RegisterCodeModeInvocationDTO;
use Ineersa\CodingAgent\Application\Message\DTO\RegisterDeferredInvocationDTO;
use Ineersa\CodingAgent\Application\Message\DTO\ReserveChildInvocationDTO;
use Ineersa\SqliteQueue\Messenger\TransportFactory;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\Serialization\Serializer;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

use function Amp\async;

/** One requester per fixed role. Reply progress is independent of tool admission. */
final class BashRegistrationClient
{
    private readonly string $incarnation;
    /** @var array<string, DeferredFuture<BashRegistrationReplyDTO>> */
    private array $pending = [];
    /** @var ?Future<void> */
    private ?Future $pump = null;
    private ?DeferredCancellation $stop = null;

    public function __construct(
        private readonly MessageBusInterface $commandBus,
        private readonly string $endpoint,
        private readonly string $sessionId,
        private readonly SerializerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly BashRequesterRoleEnum $role = BashRequesterRoleEnum::Tool,
    ) {
        $this->incarnation = bin2hex(random_bytes(16));
    }

    public function register(RegisterBashProcessDTO $registration): BashRegistrationResultDTO
    {
        $command = new RegisterBashProcessCommandDTO($registration, $this->incarnation, bin2hex(random_bytes(16)), $this->role);

        return $this->request($command)->result ?? throw new \UnexpectedValueException('Missing Bash registration result.');
    }

    public function accept(BashOwnershipRequestDTO $request): BashOwnershipStatusEnum
    {
        return $this->changeOwnership($request, BashOwnershipActionEnum::Accept);
    }

    public function retire(BashOwnershipRequestDTO $request): BashOwnershipStatusEnum
    {
        return $this->changeOwnership($request, BashOwnershipActionEnum::Retire);
    }

    public function decide(int $recordId, string $runId, BashProcessActionEnum $action): ?BashProcessSnapshotDTO
    {
        return $this->request(new BashProcessCommandDTO($recordId, $runId, $action, $this->incarnation, bin2hex(random_bytes(16)), $this->role))->process;
    }

    public function registerDeferred(DeferredToolCompletionCorrelation $invocation): string
    {
        return $this->request(new RegisterDeferredInvocationDTO($invocation, $this->incarnation, bin2hex(random_bytes(16)), $this->role))->deferredId
            ?? throw new \UnexpectedValueException('Missing deferred registration identity.');
    }

    public function registerCodeMode(DeferredToolCompletionCorrelation $invocation): string
    {
        return $this->request(new RegisterCodeModeInvocationDTO($invocation, $this->incarnation, bin2hex(random_bytes(16)), $this->role))->deferredId
            ?? throw new \UnexpectedValueException('Missing code-mode registration identity.');
    }

    public function readyCodeMode(string $runId, string $deferredId): void
    {
        $reply = $this->request(new ReadyCodeModeInvocationDTO($runId, $deferredId, $this->incarnation, bin2hex(random_bytes(16)), $this->role));
        if ($reply->deferredId !== $deferredId) {
            throw new \UnexpectedValueException('Code-mode readiness identity mismatch.');
        }
    }

    public function reserveChild(string $lifecycleId, string $parentRunId, int $parentTurnNo, string $parentToolCallId, int $parentOrderIndex, \DateTimeImmutable $deadlineAt, DeferredSubagentBatchChildIntentDTO $childIntent, ?string $parentModel): DeferredSubagentBatchProjectionDTO
    {
        return $this->request(new ReserveChildInvocationDTO($lifecycleId, $parentRunId, $parentTurnNo, $parentToolCallId, $parentOrderIndex, $deadlineAt, $childIntent, $parentModel, $this->incarnation, bin2hex(random_bytes(16)), $this->role))->childBatch
            ?? throw new \UnexpectedValueException('Missing child reservation result.');
    }

    public function recordChildLaunchSuccess(string $parentRunId, string $parentToolCallId, string $lifecycleId, \DateTimeImmutable $startedAt): void
    {
        $this->request(new RecordChildLaunchSuccessDTO($parentRunId, $parentToolCallId, $lifecycleId, $startedAt, $this->incarnation, bin2hex(random_bytes(16)), $this->role));
    }

    public function recordChildLaunchFailure(string $parentRunId, string $parentToolCallId, string $lifecycleId): void
    {
        $this->request(new RecordChildLaunchFailureDTO($parentRunId, $parentToolCallId, $lifecycleId, $this->incarnation, bin2hex(random_bytes(16)), $this->role));
    }

    private function changeOwnership(BashOwnershipRequestDTO $request, BashOwnershipActionEnum $action): BashOwnershipStatusEnum
    {
        return $this->request(new BashOwnershipCommandDTO($request, $action, $this->incarnation, bin2hex(random_bytes(16)), $this->role))->ownership
            ?? throw new \UnexpectedValueException('Missing Bash ownership result.');
    }

    private function request(RegisterBashProcessCommandDTO|BashOwnershipCommandDTO|BashProcessCommandDTO|RegisterCodeModeInvocationDTO|ReadyCodeModeInvocationDTO|ReserveChildInvocationDTO|RecordChildLaunchSuccessDTO|RecordChildLaunchFailureDTO|RegisterDeferredInvocationDTO $command): BashRegistrationReplyDTO
    {
        $errors = $this->validator->validate($command);
        if (0 !== \count($errors)) {
            throw new ValidationFailedException($command, $errors);
        }
        if ($this->stop?->getCancellation()->isRequested()) {
            $this->pump?->await();
        }
        $waiter = $this->pending[$command->correlation] = new DeferredFuture();
        if (null === $this->pump) {
            $this->stop = new DeferredCancellation();
            $this->pump = async($this->drain(...), $this->stop->getCancellation());
        }
        $pump = $this->pump;
        try {
            // The existing serialized broker sender owns concurrent publication.
            // A failed send has an unknown outcome; it never launches/relaunches
            // a shell. Callers may retry only this stable metadata operation.
            $this->commandBus->dispatch($command);

            $reply = $waiter->getFuture()->await();
            if ($reply->failed) {
                throw new \RuntimeException('Bash lifecycle request exhausted owner retries; committed outcome is unknown.');
            }

            return $reply;
        } finally {
            unset($this->pending[$command->correlation]);
            if ([] === $this->pending) {
                $this->stop?->cancel();
                $pump->await();
            }
        }
    }

    private function drain(Cancellation $cancellation): void
    {
        $transport = null;
        try {
            $transport = (new TransportFactory())->createTransport(
                'sqlite-queue://'.$this->role->queue().'?endpoint='.rawurlencode($this->endpoint),
                [], new Serializer($this->serializer),
            );
            while ([] !== $this->pending) {
                $delivery = null;
                foreach ($transport->get() as $envelope) {
                    $delivery = $envelope;
                }
                if (null === $delivery) {
                    // WAIT is a readiness notification, not polling a tool slot.
                    $transport->wait(1000, $cancellation);
                    continue;
                }
                $reply = $delivery->getMessage();
                if (!$reply instanceof BashRegistrationReplyDTO) {
                    throw new \UnexpectedValueException('Unexpected Bash registration reply type.');
                }
                $errors = $this->validator->validate($reply);
                if (0 !== \count($errors) || $reply->sessionId !== $this->sessionId || $reply->role !== $this->role) {
                    throw new \UnexpectedValueException('Invalid Bash registration reply identity.');
                }
                // Replacement workers discard replies for their dead predecessor;
                // only this incarnation's still-live waiters can be resolved.
                $waiter = $reply->incarnation === $this->incarnation ? ($this->pending[$reply->correlation] ?? null) : null;
                $transport->ack($delivery);
                if (null !== $waiter && !$waiter->isComplete()) {
                    $waiter->complete($reply);
                }
            }
        } catch (\Throwable $error) {
            if ($cancellation->isRequested()) {
                // The last caller owns shutdown of this idle reply pump. The
                // transport wraps cancellation, so inspect the stop token.
                return;
            }
            foreach ($this->pending as $waiter) {
                if (!$waiter->isComplete()) {
                    $waiter->error($error);
                }
            }
        } finally {
            $transport?->close();
            $this->pump = null;
            $this->stop = null;
        }
    }
}
