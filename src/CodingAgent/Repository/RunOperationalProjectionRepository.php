<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Contract\RunOperationalStatusDTO;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Domain\Run\RunState;
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
                // Canonical RunState carries parent/owner identity; keep the managed row aligned.
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

        // Cancel polls from long-lived LLM workers must observe cross-process
        // status writes. Doctrine's identity map would otherwise keep the
        // first-loaded Running row for the whole message lifetime.
        $this->getEntityManager()->refresh($state);

        return new RunOperationalStatusDTO($state->status);
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
        $projection->parentRunId = $state->parentRunId;
        $projection->ownerSessionId = $this->ownerSessionIdFor($state);
        $projection->status = $state->status;
        $projection->turnNo = $state->turnNo;
        $projection->activeStepId = $state->activeStepId;
        $projection->lastAppliedAdvanceKey = $state->lastAppliedAdvanceKey;
        $projection->lastAppliedCompactionKey = $state->lastAppliedCompactionKey;
        $projection->lastEventSequence = $state->lastSeq;
        $projection->transitionVersion = $state->version;

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

    private function ownerSessionIdFor(RunState $state): string
    {
        if (null === $state->parentRunId) {
            return $state->runId;
        }

        // Product policy: nested agent children are unsupported. Ownership is
        // the immediate parent session id carried on the child RunState.
        return $state->parentRunId;
    }

    private function replaceManagedGraph(RunOperationalState $state, RunOperationalState $replacement): void
    {
        foreach ([
            'parentRunId', 'ownerSessionId',
            'status', 'turnNo', 'activeStepId', 'lastAppliedAdvanceKey', 'lastAppliedCompactionKey',
            'lastEventSequence', 'transitionVersion',
        ] as $property) {
            $state->{$property} = $replacement->{$property};
        }

        $managedToolCalls = [];
        foreach ($state->toolCalls as $toolCall) {
            $managedToolCalls[$toolCall->batchId."\0".$toolCall->toolCallId] = $toolCall;
        }
        foreach ($replacement->toolCalls as $replacementToolCall) {
            $key = $replacementToolCall->batchId."\0".$replacementToolCall->toolCallId;
            $toolCall = $managedToolCalls[$key] ?? null;
            if (!$toolCall instanceof RunOperationalToolCall) {
                $replacementToolCall->run = $state;
                $state->toolCalls->add($replacementToolCall);

                continue;
            }

            $toolCall->orderIndex = $replacementToolCall->orderIndex;
            $toolCall->status = $replacementToolCall->status;
            $toolCall->attempt = $replacementToolCall->attempt;
            unset($managedToolCalls[$key]);
        }
        foreach ($managedToolCalls as $toolCall) {
            $state->toolCalls->removeElement($toolCall);
        }

        $managedHumanInputs = [];
        foreach ($state->humanInputs as $humanInput) {
            $managedHumanInputs[$humanInput->questionId] = $humanInput;
        }
        foreach ($replacement->humanInputs as $replacementHumanInput) {
            $humanInput = $managedHumanInputs[$replacementHumanInput->questionId] ?? null;
            if (!$humanInput instanceof RunOperationalHumanInput) {
                $replacementHumanInput->run = $state;
                $state->humanInputs->add($replacementHumanInput);

                continue;
            }

            $humanInput->orderIndex = $replacementHumanInput->orderIndex;
            $humanInput->continuationKind = $replacementHumanInput->continuationKind;
            $humanInput->toolCallId = $replacementHumanInput->toolCallId;
            $humanInput->status = $replacementHumanInput->status;
            unset($managedHumanInputs[$replacementHumanInput->questionId]);
        }
        foreach ($managedHumanInputs as $humanInput) {
            $state->humanInputs->removeElement($humanInput);
        }
    }
}
