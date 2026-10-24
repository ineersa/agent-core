<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundCompletion;

use Ineersa\AgentCore\Contract\CommandConsumptionPolicyInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Command\DTO\CommandConsumptionDecisionDTO;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\BackgroundCompletion\DTO\BackgroundCompletionProvenanceDTO;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionReasonEnum;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class BackgroundCompletionConsumptionPolicy implements CommandConsumptionPolicyInterface
{
    public function __construct(
        private BackgroundCompletionReceiptRepository $receipts,
        private ValidatorInterface $validator,
        private CodeModeExecutionRepository $codeModeExecutions,
        private DeferredToolCompletionRepositoryInterface $deferred,
    ) {
    }

    public function prepare(RunState $state, PendingCommand $command): ?CommandConsumptionDecisionDTO
    {
        $provenance = $command->payload['provenance'] ?? null;
        if (!$provenance instanceof BackgroundCompletionProvenanceDTO) {
            return null;
        }
        $errors = $this->validator->validate($provenance);
        if (\count($errors) > 0) {
            throw new ValidationFailedException($provenance, $errors);
        }
        $receipt = $this->receipts->receipt($provenance->identity)
            ?? throw new \RuntimeException('Background completion mailbox entry has no admission receipt.');
        if ($receipt->sourceRunId !== $provenance->sourceRunId
            || $receipt->origin !== $provenance->origin
            || $receipt->deferredId !== $provenance->deferredId
            || $receipt->sourceToolCallId !== $provenance->sourceToolCallId
            || $receipt->sourceTurnNo !== $provenance->sourceTurnNo
            || $receipt->sourceStepId !== $provenance->sourceStepId
            || $receipt->sourceAttempt !== $provenance->sourceAttempt
            || $receipt->sourceIdempotencyKey !== $provenance->sourceIdempotencyKey
            || $command->idempotencyKey !== $receipt->identity) {
            throw new \RuntimeException('Background completion mailbox provenance differs from its receipt.');
        }
        if ($receipt->destinationRunId !== $state->runId || BackgroundCompletionDispositionEnum::Pending !== $receipt->disposition) {
            return new CommandConsumptionDecisionDTO('Background completion already consumed or transferred.');
        }
        $forward = null !== $state->parentRunId && ($state->status->isTerminal() || RunStatus::Cancelling === $state->status);
        if (null !== $receipt->deferredId && $this->deferred->requiresContinuation($receipt->sourceRunId, $receipt->sourceToolCallId ?? $receipt->deferredId)) {
            return new CommandConsumptionDecisionDTO(defer: true, blockContinuation: true);
        }
        $interruption = null;
        if (BackgroundCompletionOriginEnum::CodeMode === $receipt->origin && null !== $receipt->sourceToolCallId) {
            $interruption = $this->codeModeExecutions->interruption($receipt->sourceRunId, $receipt->sourceToolCallId);
        }
        $interrupted = null !== $interruption && !\in_array($interruption, [
            CodeModeInterruptionReasonEnum::ControllerRestarted,
            CodeModeInterruptionReasonEnum::ControllerShutdown,
        ], true);
        if ($interrupted) {
            $forward = false;
        }
        $codeMode = null;
        if (BackgroundCompletionOriginEnum::CodeMode === $receipt->origin && null !== $receipt->sourceToolCallId) {
            $codeMode = ['run_id' => $receipt->sourceRunId, 'tool_call_id' => $receipt->sourceToolCallId];
        }

        return new CommandConsumptionDecisionDTO(
            $interrupted ? 'Code-mode invocation ownership ended before notification consumption.' : ($forward ? 'Background completion transferred to its root owner without resuming this child.' : null),
            ['background_completion' => [
                'identity' => $receipt->identity,
                'source_run_id' => $receipt->sourceRunId,
                'destination_run_id' => $state->runId,
                'disposition' => $forward ? BackgroundCompletionDispositionEnum::Forwarding->value : BackgroundCompletionDispositionEnum::Consumed->value,
                'origin' => $receipt->origin->value,
                'deferred_id' => $receipt->deferredId,
                'source_tool_call_id' => $receipt->sourceToolCallId,
                'source_turn_no' => $receipt->sourceTurnNo,
                'source_step_id' => $receipt->sourceStepId,
                'source_attempt' => $receipt->sourceAttempt,
                'source_idempotency_key' => $receipt->sourceIdempotencyKey,
                ...(null === $codeMode ? [] : ['code_mode' => $codeMode]),
            ]],
        );
    }
}
