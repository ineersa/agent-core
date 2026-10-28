<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DTO\DeferredToolCompletionMetadataDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;

final class InMemoryDeferredToolCompletionRepository implements DeferredToolCompletionRepositoryInterface
{
    /** @var array<string, array{correlation: DeferredToolCompletionCorrelation, status: string}> */
    private array $byDeferredId = [];

    /** @var array<string, string> runId|toolCallId => deferredId */
    private array $byRunToolCall = [];

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

    public function findMetadataByRunAndToolCall(string $runId, string $toolCallId): ?DeferredToolCompletionMetadataDTO
    {
        $id = $this->byRunToolCall[$runId.'|'.$toolCallId] ?? null;

        return null !== $id ? $this->findMetadataByDeferredId($id) : null;
    }

    public function runRequiresContinuation(string $runId): bool
    {
        return false;
    }

    public function status(string $deferredId): ?string
    {
        return $this->byDeferredId[$deferredId]['status'] ?? null;
    }

    public function markCompleted(string $deferredId): void
    {
        if (isset($this->byDeferredId[$deferredId])) {
            $this->byDeferredId[$deferredId]['status'] = 'completed';
        }
    }

    // Native host state is exercised with the real repository, not this core fake.
    public function acceptResultReference(string $deferredId, ToolPayloadReferenceDTO $reference): ToolPayloadReferenceDTO
    {
        throw new \LogicException('Native result retention requires the persistent repository.');
    }
}
