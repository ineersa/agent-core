<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DTO\DeferredToolCompletionMetadataDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;

final class InMemoryDeferredToolCompletionRepository implements DeferredToolCompletionRepositoryInterface
{
    /** @var array<string, array{correlation: DeferredToolCompletionCorrelation, status: string, acknowledgedAt: ?\DateTimeImmutable, invocationReference: ?ToolPayloadReferenceDTO, acceptedResultReference: ?ToolPayloadReferenceDTO}> */
    private array $byDeferredId = [];

    /** @var array<string, string> runId|toolCallId => deferredId */
    private array $byRunToolCall = [];

    /** @var array<string, bool> */
    private array $continuationRequired = [];

    public function registerPending(DeferredToolCompletionCorrelation $correlation): DeferredToolCompletionCorrelation
    {
        $key = $correlation->runId.'|'.$correlation->toolCallId;
        if (isset($this->byRunToolCall[$key])) {
            $deferredId = $this->byRunToolCall[$key];

            return $this->byDeferredId[$deferredId]['correlation'];
        }

        if (isset($this->byDeferredId[$correlation->deferredId])) {
            throw new \RuntimeException(\sprintf('Deferred id "%s" already registered.', $correlation->deferredId));
        }

        $this->byDeferredId[$correlation->deferredId] = [
            'correlation' => $correlation,
            'status' => 'pending',
            'acknowledgedAt' => null,
            'invocationReference' => new ToolPayloadReferenceDTO(
                'call', $correlation->runId, $correlation->turnNo, $correlation->stepId,
                $correlation->attempt, $correlation->idempotencyKey, $correlation->toolCallId,
                $correlation->orderIndex, $correlation->toolName, hash('sha256', serialize($correlation)), \strlen(serialize($correlation)),
            ),
            'acceptedResultReference' => null,
        ];
        $this->byRunToolCall[$key] = $correlation->deferredId;

        return $correlation;
    }

    public function findByRunAndToolCall(string $runId, string $toolCallId): ?DeferredToolCompletionCorrelation
    {
        $deferredId = $this->byRunToolCall[$runId.'|'.$toolCallId] ?? null;
        if (null === $deferredId) {
            return null;
        }

        $row = $this->byDeferredId[$deferredId] ?? null;
        if (null === $row) {
            return null;
        }

        return 'pending' === $row['status'] ? $row['correlation'] : null;
    }

    public function findByDeferredId(string $deferredId): ?DeferredToolCompletionCorrelation
    {
        $row = $this->byDeferredId[$deferredId] ?? null;

        return null !== $row && 'pending' === $row['status'] ? $row['correlation'] : null;
    }

    public function statusForRunAndToolCall(string $runId, string $toolCallId): ?string
    {
        $deferredId = $this->byRunToolCall[$runId.'|'.$toolCallId] ?? null;

        return null === $deferredId ? null : $this->status($deferredId);
    }

    public function findMetadataByDeferredId(string $deferredId): ?DeferredToolCompletionMetadataDTO
    {
        $row = $this->byDeferredId[$deferredId] ?? null;
        if (null === $row) {
            return null;
        }
        $correlation = $row['correlation'];
        $body = serialize($correlation);
        $reference = new ToolPayloadReferenceDTO(
            'correlation', $correlation->runId, $correlation->turnNo, $correlation->stepId,
            $correlation->attempt, $correlation->idempotencyKey, $correlation->toolCallId,
            $correlation->orderIndex, $correlation->toolName, hash('sha256', $body), \strlen($body),
        );

        return new DeferredToolCompletionMetadataDTO($deferredId, $reference, $row['status']);
    }

    public function findInvocationReferenceByDeferredId(string $deferredId): ?ToolPayloadReferenceDTO
    {
        $row = $this->byDeferredId[$deferredId] ?? null;
        if (null === $row || 'pending' !== $row['status']) {
            return null;
        }

        return $row['invocationReference'];
    }

    public function findAcceptedResultReferenceByDeferredId(string $deferredId): ?ToolPayloadReferenceDTO
    {
        $row = $this->byDeferredId[$deferredId] ?? null;
        if (null === $row || ('pending' !== $row['status'] && !($this->continuationRequired[$deferredId] ?? false))) {
            return null;
        }

        return $row['acceptedResultReference'];
    }

    public function status(string $deferredId): ?string
    {
        return $this->byDeferredId[$deferredId]['status'] ?? null;
    }

    public function acknowledgedAt(string $deferredId): ?\DateTimeImmutable
    {
        return $this->byDeferredId[$deferredId]['acknowledgedAt'] ?? null;
    }

    public function markAcknowledged(string $deferredId): void
    {
        if (!isset($this->byDeferredId[$deferredId]) || 'pending' !== $this->byDeferredId[$deferredId]['status'] || null !== $this->byDeferredId[$deferredId]['acknowledgedAt']) {
            return;
        }
        $this->byDeferredId[$deferredId]['acknowledgedAt'] = new \DateTimeImmutable();
    }

    public function acceptResult(string $deferredId, ToolPayloadReferenceDTO $reference): ToolPayloadReferenceDTO
    {
        if (!isset($this->byDeferredId[$deferredId]) || 'pending' !== $this->byDeferredId[$deferredId]['status']) {
            throw new \RuntimeException('Deferred registration cannot accept a terminal result.');
        }
        if (null !== $this->byDeferredId[$deferredId]['acceptedResultReference']) {
            return $this->byDeferredId[$deferredId]['acceptedResultReference'];
        }
        $this->byDeferredId[$deferredId]['acceptedResultReference'] = $reference;

        return $reference;
    }

    public function listPendingDeferredIdsForRun(string $runId): array
    {
        $ids = [];
        foreach ($this->byDeferredId as $deferredId => $row) {
            if ($row['correlation']->runId === $runId && ('pending' === $row['status'] || ($this->continuationRequired[$deferredId] ?? false))) {
                $ids[] = $deferredId;
            }
        }

        return $ids;
    }

    public function hasRetainedEvidenceForRun(string $runId): bool
    {
        foreach ($this->byDeferredId as $deferredId => $row) {
            if ($row['correlation']->runId !== $runId) {
                continue;
            }
            if ('pending' === $row['status'] || ($this->continuationRequired[$deferredId] ?? false) || null !== $row['acceptedResultReference']) {
                return true;
            }
        }

        return false;
    }

    public function markCompleted(string $deferredId): void
    {
        if (isset($this->byDeferredId[$deferredId])) {
            $this->byDeferredId[$deferredId]['status'] = 'completed';
        }
    }

    public function requireContinuation(string $deferredId, bool $required): void
    {
        $this->continuationRequired[$deferredId] = $required;
    }

    public function requiresContinuation(string $runId, string $toolCallId): bool
    {
        return $this->continuationRequired[$this->byRunToolCall[$runId.'|'.$toolCallId] ?? ''] ?? false;
    }

    public function runRequiresContinuation(string $runId): bool
    {
        foreach ($this->byDeferredId as $deferredId => $row) {
            if ($row['correlation']->runId === $runId && ($this->continuationRequired[$deferredId] ?? false)) {
                return true;
            }
        }

        return false;
    }
}
