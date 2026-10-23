<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\BackgroundCompletion;

use Ineersa\AgentCore\Contract\CommandConsumptionPolicyInterface;
use Ineersa\AgentCore\Domain\Command\DTO\CommandConsumptionDecisionDTO;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\BackgroundCompletion\DTO\BackgroundCompletionProvenanceDTO;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class BackgroundCompletionConsumptionPolicy implements CommandConsumptionPolicyInterface
{
    public function __construct(private BackgroundCompletionReceiptRepository $receipts, private ValidatorInterface $validator, private CodeModeExecutionRepository $codeModeExecutions)
    {
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
        if ($receipt->sourceRunId !== $provenance->sourceRunId || $command->idempotencyKey !== $receipt->identity) {
            throw new \RuntimeException('Background completion mailbox provenance differs from its receipt.');
        }
        if ($receipt->destinationRunId !== $state->runId || BackgroundCompletionDispositionEnum::Pending !== $receipt->disposition) {
            return new CommandConsumptionDecisionDTO('Background completion already consumed or transferred.');
        }
        $forward = null !== $state->parentRunId && ($state->status->isTerminal() || RunStatus::Cancelling === $state->status);
        $interrupted = null !== $this->codeModeExecutions->completionInterruption($receipt->identity);
        if ($interrupted) {
            $forward = false;
        }

        return new CommandConsumptionDecisionDTO(
            $interrupted ? 'Code-mode invocation ownership ended before notification consumption.' : ($forward ? 'Background completion transferred to its root owner without resuming this child.' : null),
            ['background_completion' => [
                'identity' => $receipt->identity,
                'source_run_id' => $receipt->sourceRunId,
                'destination_run_id' => $state->runId,
                'disposition' => $forward ? BackgroundCompletionDispositionEnum::Forwarding->value : BackgroundCompletionDispositionEnum::Consumed->value,
            ]],
        );
    }
}
