<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DTO\DeferredToolCompletionMetadataDTO;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;

final class InMemoryDeferredToolCompletionRepository implements DeferredToolCompletionRepositoryInterface
{
    /** @var array<string, array{correlation: DeferredToolCompletionCorrelation, status: string, readyAt: ?\DateTimeImmutable, backgroundedAt: ?\DateTimeImmutable, accepted: ?ToolPayloadReferenceDTO}> */
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
            'readyAt' => null,
            'backgroundedAt' => null,
            'accepted' => null,
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

        return new DeferredToolCompletionMetadataDTO($deferredId, $reference, $row['status'], $row['readyAt'], $row['backgroundedAt'], $row['accepted']);
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

    public function markReady(string $deferredId): void
    {
        $row = $this->byDeferredId[$deferredId] ?? null;
        if (null === $row) {
            throw new \RuntimeException('Unknown deferred id.');
        }
        if (null !== $row['readyAt']) {
            return;
        }
        if ('pending' !== $row['status']) {
            throw new \RuntimeException('Deferred completion is no longer pending for readiness.');
        }
        $this->byDeferredId[$deferredId]['readyAt'] = new \DateTimeImmutable();
    }

    public function markBackgrounded(string $deferredId, \DateTimeImmutable $acceptedAt): void
    {
        $row = $this->byDeferredId[$deferredId] ?? null;
        if (null === $row) {
            throw new \RuntimeException('Unknown deferred id.');
        }
        if (null !== $row['backgroundedAt']) {
            return;
        }
        if ('pending' !== $row['status'] || null === $row['readyAt'] || null !== $row['accepted']) {
            throw new \RuntimeException('Deferred completion is not ready for backgrounding.');
        }
        $this->byDeferredId[$deferredId]['backgroundedAt'] = $acceptedAt;
    }

    public function acceptResultReference(string $deferredId, ToolPayloadReferenceDTO $reference): ToolPayloadReferenceDTO
    {
        $row = $this->byDeferredId[$deferredId] ?? null;
        if (null === $row) {
            throw new \RuntimeException('Unknown deferred id.');
        }
        if (null !== $row['accepted']) {
            if ([$row['accepted']->sha256, $row['accepted']->bytes] !== [$reference->sha256, $reference->bytes]) {
                throw new \RuntimeException('Deferred completion already accepted a different result.');
            }

            return $row['accepted'];
        }
        $this->byDeferredId[$deferredId]['accepted'] = $reference;

        return $reference;
    }
}
