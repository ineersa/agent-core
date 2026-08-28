<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Contract\RunOperationalStatusDTO;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Entity\RunOperationalHumanInput;
use Ineersa\CodingAgent\Entity\RunOperationalState;
use Ineersa\CodingAgent\Entity\RunOperationalToolCall;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Disposable, payload-free coordination projection. Canonical events remain authoritative.
 *
 * @extends ServiceEntityRepository<RunOperationalState>
 */
final class RunOperationalProjectionRepository extends ServiceEntityRepository implements RunOperationalStatusReaderInterface
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly ValidatorInterface $validator,
        private readonly SubagentRunMetadataReader $metadataReader,
    ) {
        parent::__construct($registry, RunOperationalState::class);
    }

    public function replace(RunState $runState): void
    {
        $replacement = $this->projection($runState);
        $violations = $this->validator->validate($replacement);
        if (0 !== $violations->count()) {
            throw new ValidationFailedException($replacement, $violations);
        }

        $entityManager = $this->getEntityManager();
        $entityManager->wrapInTransaction(function () use ($entityManager, $runState, $replacement): void {
            $state = $this->find($runState->runId);
            if (!$state instanceof RunOperationalState) {
                $entityManager->persist($replacement);
            } else {
                // Parent/owner identity was resolved at the first canonical projection.
                $this->replaceManagedGraph($state, $replacement);
            }

            $entityManager->flush();
        });
    }

    public function findOperationalStatus(string $runId): ?RunOperationalStatusDTO
    {
        $state = $this->find($runId);
        if (!$state instanceof RunOperationalState) {
            return null;
        }

        return new RunOperationalStatusDTO($state->runId, $state->status, $this->currentOperation($state));
    }

    public function deleteForOwnerSession(string $ownerSessionId): int
    {
        $states = $this->findBy(['ownerSessionId' => $ownerSessionId]);
        if ([] === $states) {
            return 0;
        }

        $entityManager = $this->getEntityManager();
        $entityManager->wrapInTransaction(static function () use ($entityManager, $states): void {
            foreach ($states as $state) {
                $entityManager->remove($state);
            }
            $entityManager->flush();
        });

        return \count($states);
    }

    private function projection(RunState $state): RunOperationalState
    {
        $projection = new RunOperationalState();
        $projection->runId = $state->runId;
        $projection->status = $state->status;
        $projection->turnNo = $state->turnNo;
        $projection->activeStepId = $state->activeStepId;
        $projection->operationTurnNo = $state->currentOperation?->turnNo;
        $projection->operationStepId = $state->currentOperation?->stepId;
        $projection->operationAttempt = $state->currentOperation?->attempt;
        $projection->operationKey = $state->currentOperation?->idempotencyKey;
        $projection->lastAppliedAdvanceKey = $state->lastAppliedAdvanceKey;
        $projection->lastAppliedCompactionKey = $state->lastAppliedCompactionKey;
        $projection->retryableFailure = $state->retryableFailure;
        $projection->retryAttempts = $state->retryAttempts;
        $projection->lastEventSequence = $state->lastSeq;
        $projection->transitionVersion = $state->version;
        $this->resolveOwnership($projection, []);

        foreach ($state->currentToolCalls as $descriptor) {
            $toolCall = new RunOperationalToolCall();
            $toolCall->run = $projection;
            $toolCall->batchId = $descriptor->batchId;
            $toolCall->toolCallId = $descriptor->toolCallId;
            $toolCall->orderIndex = $descriptor->orderIndex;
            $toolCall->status = $descriptor->status;
            $toolCall->attempt = $descriptor->attempt;
            $projection->toolCalls->add($toolCall);
        }

        foreach ($state->pendingHumanInputRequests as $orderIndex => $request) {
            $humanInput = new RunOperationalHumanInput();
            $humanInput->run = $projection;
            $humanInput->questionId = $request->questionId;
            $humanInput->orderIndex = $orderIndex;
            $humanInput->continuationKind = $request->continuationKind;
            $humanInput->toolCallId = $request->toolCallId();
            $projection->humanInputs->add($humanInput);
        }

        return $projection;
    }

    /** @param array<string, true> $visited */
    private function resolveOwnership(RunOperationalState $projection, array $visited): void
    {
        if (isset($visited[$projection->runId])) {
            throw new \LogicException(\sprintf('Child run ownership cycle detected for "%s".', $projection->runId));
        }
        $visited[$projection->runId] = true;

        $projection->parentRunId = $this->metadataReader->readParentRunId($projection->runId);
        if (null === $projection->parentRunId) {
            $projection->ownerSessionId = $projection->runId;

            return;
        }

        $parent = $this->find($projection->parentRunId);
        if ($parent instanceof RunOperationalState) {
            $projection->ownerSessionId = $parent->ownerSessionId;

            return;
        }

        $parentProjection = new RunOperationalState();
        $parentProjection->runId = $projection->parentRunId;
        $this->resolveOwnership($parentProjection, $visited);
        $projection->ownerSessionId = $parentProjection->ownerSessionId;
    }

    private function replaceManagedGraph(RunOperationalState $state, RunOperationalState $replacement): void
    {
        foreach ([
            'status', 'turnNo', 'activeStepId', 'operationTurnNo', 'operationStepId', 'operationAttempt',
            'operationKey', 'lastAppliedAdvanceKey', 'lastAppliedCompactionKey', 'retryableFailure',
            'retryAttempts', 'lastEventSequence', 'transitionVersion',
        ] as $property) {
            $state->{$property} = $replacement->{$property};
        }

        foreach ($state->toolCalls->toArray() as $toolCall) {
            $state->toolCalls->removeElement($toolCall);
        }
        foreach ($replacement->toolCalls as $toolCall) {
            $toolCall->run = $state;
            $state->toolCalls->add($toolCall);
        }

        foreach ($state->humanInputs->toArray() as $humanInput) {
            $state->humanInputs->removeElement($humanInput);
        }
        foreach ($replacement->humanInputs as $humanInput) {
            $humanInput->run = $state;
            $state->humanInputs->add($humanInput);
        }
    }

    private function currentOperation(RunOperationalState $state): ?CurrentOperationDTO
    {
        if (null === $state->operationKey) {
            return null;
        }
        if (null === $state->operationTurnNo || null === $state->operationStepId || null === $state->operationAttempt) {
            throw new \UnexpectedValueException('Persisted current operation is incomplete.');
        }

        return new CurrentOperationDTO(
            $state->operationTurnNo,
            $state->operationStepId,
            $state->operationAttempt,
            $state->operationKey,
        );
    }
}
