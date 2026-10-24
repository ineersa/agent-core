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
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final readonly class BackgroundCompletionConsumptionPolicy implements CommandConsumptionPolicyInterface
{
    public function __construct(private BackgroundCompletionReceiptRepository $receipts, private ValidatorInterface $validator, private DeferredToolCompletionRepositoryInterface $deferred)
    {
    }

    public function interrupted(?string $id): bool
    {
        return null !== $id && null !== $this->deferred->findMetadataByDeferredId($id)?->interruptionReason;
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
        $interrupted = $this->interrupted($receipt->deferredId);
        $forward = !$interrupted && null !== $state->parentRunId && ($state->status->isTerminal() || RunStatus::Cancelling === $state->status);

        return new CommandConsumptionDecisionDTO(
            $interrupted ? 'Interrupted invocation completion retired.' : ($forward ? 'Background completion transferred to its root owner without resuming this child.' : null),
            ['background_completion' => [
                'identity' => $receipt->identity,
                'source_run_id' => $receipt->sourceRunId,
                'destination_run_id' => $state->runId,
                'disposition' => $forward ? BackgroundCompletionDispositionEnum::Forwarding->value : BackgroundCompletionDispositionEnum::Consumed->value,
            ]],
        );
    }
}
