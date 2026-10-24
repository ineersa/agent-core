<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\CodeMode;

use Ineersa\AgentCore\Contract\CoordinationActionValidatorInterface;
use Ineersa\AgentCore\Contract\Extension\EssentialAfterTurnHookInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Coordination\DTO\DispatchLocalMetadataMessageDTO;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Extension\AfterTurnCommitHookContext;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;
use Ineersa\CodingAgent\Application\CodeMode\DTO\InterruptCodeModeDTO;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Capture exact invocation identities before committing cancellation or branch discard. */
final readonly class CodeModeInterruptionHookSubscriber implements EssentialAfterTurnHookInterface, CoordinationActionValidatorInterface
{
    public function __construct(
        private CodeModeExecutionRepository $executions,
        private ValidatorInterface $validator,
        private DeferredToolCompletionRepositoryInterface $deferred,
        private RunHistorySourceProviderInterface $histories,
        private BackgroundCompletionReceiptRepository $receipts,
    ) {
    }

    public function prepareAfterTurnCommit(AfterTurnCommitHookContext $context, int $predecessorSequence): array
    {
        $actions = [];
        if (\in_array($context->status, [RunStatus::Cancelling->value, RunStatus::Cancelled->value], true)) {
            $actions[] = $this->action($context->runId, CodeModeInterruptionReasonEnum::Cancelled);
        }
        foreach ($context->events as $event) {
            if (RunEventTypeEnum::HistoryTailDiscarded->value === $event->type) {
                $afterTurn = $event->payload['after_turn_no'] ?? null;
                if (!\is_int($afterTurn) || $afterTurn < 0) {
                    throw new \RuntimeException('Code-mode history fencing requires the committed discard boundary.');
                }
                $actions[] = $this->action($context->runId, CodeModeInterruptionReasonEnum::HistoryDiscarded);
            }
        }

        return array_values(array_filter($actions));
    }

    #[AsMessageHandler(bus: 'agent.command.bus')]
    public function interrupt(InterruptCodeModeDTO $action): void
    {
        $this->validate($action);
        foreach ($action->identities as $identity) {
            $this->executions->interrupt($identity, $action->reason);
        }
    }

    public function supports(object $action): bool
    {
        return $action instanceof InterruptCodeModeDTO;
    }

    public function validate(object $action): void
    {
        $errors = $this->validator->validate($action);
        if (\count($errors) > 0) {
            throw new ValidationFailedException($action, $errors);
        }
    }

    private function action(string $runId, CodeModeInterruptionReasonEnum $reason): ?DispatchLocalMetadataMessageDTO
    {
        $identities = [];
        foreach ($this->executions->identitiesForRun($runId) as $identity) {
            $metadata = $this->deferred->findMetadataByDeferredId($identity);
            if (null === $metadata) {
                continue;
            }
            $row = $this->executions->invocation($runId, $metadata->reference->toolCallId);
            if (null === $row) {
                continue;
            }
            $pending = 'pending' === $metadata->status;
            $receipt = $this->receipts->receipt($identity);
            $admittedUnconsumed = null !== $receipt && \in_array($receipt->disposition, [
                BackgroundCompletionDispositionEnum::Pending,
                BackgroundCompletionDispositionEnum::Forwarding,
            ], true);
            $controllerParked = \in_array($row['interruption_reason'] ?? null, [
                CodeModeInterruptionReasonEnum::ControllerRestarted->value,
                CodeModeInterruptionReasonEnum::ControllerShutdown->value,
            ], true);
            if (!$pending && !$admittedUnconsumed && !$controllerParked) {
                continue;
            }
            if (null !== $row['interruption_reason'] && !$controllerParked && !$admittedUnconsumed) {
                continue;
            }
            $identities[] = $identity;
        }
        if (CodeModeInterruptionReasonEnum::HistoryDiscarded === $reason && [] !== $identities) {
            // Discard is prepared behind the selected tip. Reuse immutable anchor
            // ancestry, not displayed turn-number comparisons or archive bodies.
            $source = $this->histories->historySource($runId);
            $identities = array_values(array_filter($identities, function (string $identity) use ($source, $runId): bool {
                $metadata = $this->deferred->findMetadataByDeferredId($identity)
                    ?? throw new \RuntimeException('Code-mode discard lost its original invocation metadata.');
                $reference = $metadata->reference;
                $retained = $source->log->pendingToolInvocation($source->path, $runId, $reference->toolCallId, requireCurrentStep: false);

                return null === $retained || $retained->turnNo !== $reference->turnNo || $retained->stepId !== $reference->stepId;
            }));
        }

        return [] === $identities ? null : new DispatchLocalMetadataMessageDTO(new InterruptCodeModeDTO($identities, $reason));
    }
}
