<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Entity;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;
use Ineersa\CodingAgent\Application\BackgroundCompletion\DTO\BackgroundCompletionReceiptDTO;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Validator\Exception\ValidationFailedException;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** @extends ServiceEntityRepository<BackgroundCompletionReceipt> */
final class BackgroundCompletionReceiptRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private SerializerInterface&DecoderInterface $serializer,
        private ValidatorInterface $validator,
    ) {
        parent::__construct($registry, BackgroundCompletionReceipt::class);
    }

    public function receipt(string $identity): ?BackgroundCompletionReceiptDTO
    {
        $row = $this->getEntityManager()->getConnection()->fetchAssociative('SELECT * FROM background_completion_receipt WHERE identity = ?', [$identity]);

        return false === $row ? null : $this->snapshot($row);
    }

    /** Whether native background work still owes this session a completion. */
    public function hasOutstandingWork(string $rootRunId): bool
    {
        // Source rows bridge execution/transport -> receipt admission. Checking
        // only running work misses terminal results still in transit; checking
        // only receipts misses work that has not produced its result yet.
        // Once admitted, the receipt owns pending/forwarding/consumed state.
        return (bool) $this->getEntityManager()->getConnection()->fetchOne(<<<'SQL'
            SELECT EXISTS (
                SELECT 1 FROM background_completion_receipt
                WHERE root_run_id = :root AND disposition IN ('pending', 'forwarding')
            ) OR EXISTS (
                SELECT 1 FROM deferred_subagent_batch batch
                LEFT JOIN background_completion_receipt receipt
                    ON receipt.identity = 'background-child-result-' || batch.lifecycle_id
                WHERE batch.parent_run_id = :root AND batch.backgrounded_at IS NOT NULL
                    AND receipt.identity IS NULL
            ) OR EXISTS (
                SELECT 1 FROM background_process process
                LEFT JOIN run_operational_state state ON state.run_id = process.session_id
                LEFT JOIN deferred_subagent_child child ON child.child_run_id = process.session_id
                LEFT JOIN deferred_subagent_batch batch ON batch.lifecycle_id = child.batch_lifecycle_id
                LEFT JOIN background_completion_receipt receipt
                    ON receipt.identity = 'background-process-' || process.id
                WHERE process.backgrounded_at IS NOT NULL AND process.completion_notified_at IS NULL
                    AND receipt.identity IS NULL
                    AND (process.session_id = :root OR state.owner_session_id = :root OR batch.parent_run_id = :root)
            ) OR EXISTS (
                SELECT 1 FROM tool_deferred_completion invocation
                LEFT JOIN run_operational_state state ON state.run_id = invocation.run_id
                LEFT JOIN deferred_subagent_child child ON child.child_run_id = invocation.run_id
                LEFT JOIN deferred_subagent_batch batch ON batch.lifecycle_id = child.batch_lifecycle_id
                LEFT JOIN background_completion_receipt receipt ON receipt.identity = invocation.deferred_id
                WHERE invocation.tool_name = 'code_mode' AND invocation.status = 'pending'
                    AND invocation.backgrounded_at IS NOT NULL AND receipt.identity IS NULL
                    AND (invocation.interruption_reason IS NULL OR invocation.interruption_reason = 'controller_lost')
                    AND (invocation.run_id = :root OR state.owner_session_id = :root OR batch.parent_run_id = :root)
            )
            SQL, ['root' => $rootRunId]);
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
            'deferred_id' => $receipt->deferredId,
            'invocation_reference_json' => null === $receipt->invocation ? null : $this->serializer->serialize($receipt->invocation, 'json'),
            'origin' => $this->serializer->serialize($receipt->origin, 'json'),
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
        $invocation = null;
        if (null !== ($row['invocation_reference_json'] ?? null) && '' !== $row['invocation_reference_json']) {
            $invocation = $this->serializer->deserialize((string) $row['invocation_reference_json'], ToolPayloadReferenceDTO::class, 'json');
            $errors = $this->validator->validate($invocation);
            if (0 !== \count($errors)) {
                throw new ValidationFailedException($invocation, $errors);
            }
        }

        return new BackgroundCompletionReceiptDTO(
            (string) $row['identity'], (string) $row['source_run_id'],
            (string) $row['accepted_destination_run_id'], (string) $row['destination_run_id'],
            (string) $row['root_run_id'], (string) $row['text'], (string) $row['forwarded_text'],
            BackgroundCompletionDispositionEnum::from((string) $row['disposition']),
            isset($row['deferred_id']) && '' !== $row['deferred_id'] ? (string) $row['deferred_id'] : null,
            $invocation,
            $this->serializer->decode((string) $row['origin'], 'json'),
        );
    }
}
