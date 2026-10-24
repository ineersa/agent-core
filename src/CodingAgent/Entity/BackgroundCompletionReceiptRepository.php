<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionOriginEnum;
use Ineersa\CodingAgent\Application\BackgroundCompletion\DTO\BackgroundCompletionReceiptDTO;

/** @extends ServiceEntityRepository<BackgroundCompletionReceipt> */
final class BackgroundCompletionReceiptRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, BackgroundCompletionReceipt::class);
    }

    public function receipt(string $identity): ?BackgroundCompletionReceiptDTO
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative('SELECT * FROM background_completion_receipt WHERE identity = ?', [$identity]);

        return false === $row ? null : $this->snapshot($row);
    }

    /** @return list<BackgroundCompletionReceiptDTO> */
    public function recoverableDeliveries(string $rootRunId): array
    {
        // Worker-failure terminalization need not schedule another AdvanceRun.
        // Its already-admitted notification still needs owner-side consumption.
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT receipt.* FROM background_completion_receipt receipt
             LEFT JOIN run_operational_state state ON state.run_id = receipt.destination_run_id
             WHERE receipt.root_run_id = ? AND (
                 receipt.disposition = ? OR (
                     receipt.disposition = ? AND state.parent_run_id IS NOT NULL
                     AND state.status IN (?, ?, ?)
                 )
             )',
            [$rootRunId, BackgroundCompletionDispositionEnum::Forwarding->value,
                BackgroundCompletionDispositionEnum::Pending->value,
                RunStatus::Completed->value, RunStatus::Failed->value, RunStatus::Cancelled->value],
        );

        return array_map($this->snapshot(...), $rows);
    }

    public function record(BackgroundCompletionReceiptDTO $receipt): void
    {
        $connection = $this->getEntityManager()->getConnection();
        $current = $this->receipt($receipt->identity);
        if (null !== $current) {
            // Recovery cannot replace a consumed receipt or move an accepted
            // destination back from root to a resumed original child.
            if (BackgroundCompletionDispositionEnum::Consumed === $current->disposition || BackgroundCompletionDispositionEnum::Pending === $current->disposition) {
                return;
            }
            $connection->update('background_completion_receipt', [
                'disposition' => $receipt->disposition->value,
                'destination_run_id' => $current->destinationRunId,
            ], ['identity' => $receipt->identity]);

            return;
        }
        $connection->insert('background_completion_receipt', [
            'identity' => $receipt->identity,
            'source_run_id' => $receipt->sourceRunId,
            'accepted_destination_run_id' => $receipt->acceptedDestinationRunId,
            'destination_run_id' => $receipt->destinationRunId,
            'root_run_id' => $receipt->rootRunId,
            'text' => $receipt->text,
            'forwarded_text' => $receipt->forwardedText,
            'disposition' => $receipt->disposition->value,
            'origin' => $receipt->origin->value,
            'deferred_id' => $receipt->deferredId,
            'source_tool_call_id' => $receipt->sourceToolCallId,
            'source_turn_no' => $receipt->sourceTurnNo,
            'source_step_id' => $receipt->sourceStepId,
            'source_attempt' => $receipt->sourceAttempt,
            'source_idempotency_key' => $receipt->sourceIdempotencyKey,
        ]);
    }

    public function consume(string $identity, string $runId, bool $forward): void
    {
        $connection = $this->getEntityManager()->getConnection();
        if ($forward) {
            $connection->executeStatement('UPDATE background_completion_receipt SET disposition = ?, destination_run_id = root_run_id WHERE identity = ? AND destination_run_id = ? AND disposition = ?', [BackgroundCompletionDispositionEnum::Forwarding->value, $identity, $runId, BackgroundCompletionDispositionEnum::Pending->value]);
        } else {
            $connection->executeStatement('UPDATE background_completion_receipt SET disposition = ? WHERE identity = ? AND destination_run_id = ? AND disposition = ?', [BackgroundCompletionDispositionEnum::Consumed->value, $identity, $runId, BackgroundCompletionDispositionEnum::Pending->value]);
        }
    }

    /** @param array<string, mixed> $row */
    private function snapshot(array $row): BackgroundCompletionReceiptDTO
    {
        return new BackgroundCompletionReceiptDTO(
            (string) $row['identity'], (string) $row['source_run_id'],
            (string) $row['accepted_destination_run_id'], (string) $row['destination_run_id'],
            (string) $row['root_run_id'], (string) $row['text'], (string) $row['forwarded_text'],
            BackgroundCompletionDispositionEnum::from((string) $row['disposition']),
            BackgroundCompletionOriginEnum::from((string) $row['origin']),
            isset($row['deferred_id']) && '' !== $row['deferred_id'] ? (string) $row['deferred_id'] : null,
            isset($row['source_tool_call_id']) && '' !== $row['source_tool_call_id'] ? (string) $row['source_tool_call_id'] : null,
            isset($row['source_turn_no']) && '' !== $row['source_turn_no'] ? (int) $row['source_turn_no'] : null,
            isset($row['source_step_id']) && '' !== $row['source_step_id'] ? (string) $row['source_step_id'] : null,
            isset($row['source_attempt']) && '' !== $row['source_attempt'] ? (int) $row['source_attempt'] : null,
            isset($row['source_idempotency_key']) && '' !== $row['source_idempotency_key'] ? (string) $row['source_idempotency_key'] : null,
        );
    }
}
