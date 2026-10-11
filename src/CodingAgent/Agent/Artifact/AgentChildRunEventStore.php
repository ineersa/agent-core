<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Session\Contract\RunSequenceAllocatorInterface;
use Ineersa\CodingAgent\Session\EventLogMaxSeqBootstrapReader;
use Ineersa\CodingAgent\Session\JsonlPhysicalReadObservation;
use Ineersa\CodingAgent\Session\JsonlRunEventLog;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Parent-scoped EventStoreInterface implementation for child agent runs.
 *
 * Writes and reads RunEvent entries at the parent-scoped artifact path:
 *
 *   .hatfield/sessions/<parentRunId>/artifacts/agents/<artifactId>/events.jsonl
 *
 * Sequence allocation uses {@see \Ineersa\CodingAgent\Session\FileRunSequenceAllocator::COUNTER_BASENAME} next to that log.
 * Uses Symfony Lock via the injected {@see LockFactory} (typically flock-backed) keyed by the child agentRunId to
 * protect concurrent appends.  Reuses EventPayloadNormalizer for
 * canonical event serialization.
 *
 * Does NOT create top-level .hatfield/sessions/<agentRunId>/
 * directories — child events are entirely parent-scoped.
 *
 * Validates that embedded runId in each event matches the bound
 * agentRunId. Mismatches throw on append.  allFor() only returns
 * events for the bound agentRunId; other run IDs return an empty list.
 *
 * Path resolution and validation are delegated to
 * {@see SessionAgentArtifactPathResolver}.
 *
 * Append/sequence/bootstrap mechanics and the decode/denormalize/schema/sort
 * primitives are delegated to {@see JsonlRunEventLog}; this class owns the
 * bound-run validation, child artifact path, streaming reads, and child-specific
 * diagnostics.
 */
final class AgentChildRunEventStore implements \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface
{
    private readonly JsonlRunEventLog $eventLog;

    public function __construct(
        private readonly SessionAgentArtifactPathResolver $pathResolver,
        EventPayloadNormalizer $eventPayloadNormalizer,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        RunSequenceAllocatorInterface $sequenceAllocator,
        private readonly string $parentRunId,
        private readonly string $agentRunId,
        private readonly string $artifactId,
        EventLogMaxSeqBootstrapReader $bootstrapReader = new EventLogMaxSeqBootstrapReader(),
    ) {
        $this->pathResolver->validatePathComponent($parentRunId, 'parentRunId');
        $this->pathResolver->validatePathComponent($artifactId, 'artifactId');
        $this->eventLog = new JsonlRunEventLog($eventPayloadNormalizer, $lockFactory, $sequenceAllocator, $bootstrapReader, $logger);
    }

    /**
     * Recovery-only tail read of durable child events.jsonl (not for steady-state supervision).
     *
     * The first decoded record with seq <= $cursor stops the tail read; records before it are intentionally not decoded.
     *
     * @return list<RunEvent> Events with seq > $cursor, sorted ascending. Sequence holes are preserved.
     */
    public function readAfterSeq(int $cursor): array
    {
        $path = $this->eventsPath();
        $lock = $this->lockFactory->createLock("hatfield-run-{$this->agentRunId}");
        $lock->acquire(true);

        try {
            $observation = new JsonlPhysicalReadObservation();
            $decodedEventCount = 0;
            $events = [];

            try {
                foreach ($this->eventLog->reverseLines($path, $observation) as $line) {
                    $event = $this->eventFromLine($line);
                    if (null === $event) {
                        continue;
                    }

                    ++$decodedEventCount;
                    if ($event->seq <= $cursor) {
                        break;
                    }

                    $events[] = $event;
                }
            } finally {
                $this->logPhysicalRead('readAfterSeq', $observation, $decodedEventCount);
            }

            return array_reverse($events);
        } finally {
            $lock->release();
        }
    }

    public function appendTransition(array $events, array $work): array
    {
        if (($work['run_id'] ?? null) !== $this->agentRunId) {
            throw new \InvalidArgumentException('Child prepared work identity mismatch.');
        }
        if ([] === $events) {
            if (!\is_string($work['run_id'] ?? null)) {
                throw new \InvalidArgumentException('Prepared decision requires run identity.');
            }
        }
        foreach ($events as $event) {
            if ($event->runId !== $events[0]->runId || $event->runId !== $this->agentRunId) {
                throw new \InvalidArgumentException('Transition events have inconsistent run identity.');
            }
        }

        return $this->eventLog->appendMany($this->eventsPath(), $events, runLabel: 'child run', dirMode: SessionAgentArtifactPathResolver::DIR_PERMISSIONS, work: $work);
    }

    public function verifiedPendingTransition(string $runId): ?\Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO
    {
        if ($runId !== $this->agentRunId) {
            throw new \InvalidArgumentException('Child pending transition identity mismatch.');
        }

        return $this->eventLog->verifiedPendingTransition($this->eventsPath(), $runId);
    }

    public function verifiedPendingBatch(string $runId, string $identity): array
    {
        if ($runId !== $this->agentRunId) {
            throw new \InvalidArgumentException('Child pending batch identity mismatch.');
        }

        return $this->eventLog->verifiedPendingBatch($this->eventsPath(), $runId, $identity);
    }

    public function finalizeVerifiedTransition(string $runId, string $identity): void
    {
        if ($runId !== $this->agentRunId) {
            throw new \InvalidArgumentException('Child finalization identity mismatch.');
        }
        $this->eventLog->finalizeVerifiedTransition($this->eventsPath(), $runId, $identity);
    }

    public function assertTransitionReady(string $runId): void
    {
        if ($runId !== $this->agentRunId) {
            throw new \InvalidArgumentException('Child transition identity mismatch.');
        }
        $this->eventLog->assertTransitionReady($this->eventsPath(), $runId);
    }

    public function historySource(): \Ineersa\CodingAgent\Session\RunHistorySourceDTO
    {
        return new \Ineersa\CodingAgent\Session\RunHistorySourceDTO($this->eventLog, $this->eventsPath());
    }

    public function latestSequenceFor(string $runId): ?int
    {
        foreach ($this->reverseFor($runId) as $event) {
            return $event->seq;
        }

        return null;
    }

    public function firstFor(string $runId): ?RunEvent
    {
        if ($runId !== $this->agentRunId) {
            return null;
        }

        foreach ($this->streamDecodedEvents('firstFor') as $event) {
            return $event;
        }

        return null;
    }

    /**
     * @return \Generator<int, RunEvent>
     */
    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        if ($runId !== $this->agentRunId || $startSeq < 1 || $endSeq < $startSeq) {
            return;
        }

        foreach ($this->eventLog->indexedLines($this->eventsPath(), $runId, $startSeq, $endSeq) as $line) {
            $event = $this->eventFromLine($line);
            if (null !== $event) {
                yield $event;
            }
        }
    }

    /**
     * @return \Generator<int, RunEvent>
     */
    public function reverseFor(string $runId): iterable
    {
        if ($runId !== $this->agentRunId) {
            return;
        }

        $path = $this->eventsPath();
        $observation = new JsonlPhysicalReadObservation();
        $decodedEventCount = 0;

        try {
            foreach ($this->eventLog->reverseLines($path, $observation) as $line) {
                $event = $this->eventFromLine($line);
                if (null === $event) {
                    continue;
                }

                ++$decodedEventCount;
                yield $event;
            }
        } finally {
            $this->logPhysicalRead('reverseFor', $observation, $decodedEventCount);
        }
    }

    /**
     * @return list<RunEvent>
     */
    public function allFor(string $runId): array
    {
        if ($runId !== $this->agentRunId) {
            return [];
        }

        $events = [];
        foreach ($this->streamDecodedEvents('allFor') as $event) {
            $events[] = $event;
        }

        return $this->eventLog->sortBySeq($events);
    }

    /**
     * @return \Generator<int, RunEvent>
     */
    private function streamDecodedEvents(string $method): \Generator
    {
        $path = $this->eventsPath();
        $observation = new JsonlPhysicalReadObservation();
        $decodedEventCount = 0;

        try {
            foreach ($this->eventLog->forwardLines($path, $observation) as $line) {
                $event = $this->eventFromLine($line);
                if (null === $event) {
                    continue;
                }

                ++$decodedEventCount;
                yield $event;
            }
        } finally {
            $this->logPhysicalRead($method, $observation, $decodedEventCount);
        }
    }

    /**
     * @param non-empty-string $method
     */
    private function logPhysicalRead(
        string $method,
        JsonlPhysicalReadObservation $observation,
        int $decodedEventCount,
    ): void {
        $this->logger->debug('Child event store physical JSONL read', [
            'run_id' => $this->agentRunId,
            'parent_run_id' => $this->parentRunId,
            'artifact_id' => $this->artifactId,
            'component' => 'agent.artifact',
            'event_type' => 'child_event_store.physical_read',
            'method' => $method,
            'archive_bytes_read' => $observation->archiveBytesRead(),
            'lines_yielded' => $observation->linesYielded(),
            'decoded_event_count' => $decodedEventCount,
            'full_scan' => $observation->fullScan(),
            'early_exit' => $observation->earlyExit(),
            'reached_eof' => $observation->reachedEof(),
        ]);
    }

    private function eventFromLine(string $line): ?RunEvent
    {
        $trimmedLine = trim($line);
        if ('' === $trimmedLine) {
            return null;
        }

        try {
            $payload = $this->eventLog->decodeLine($trimmedLine);
        } catch (\JsonException $e) {
            throw new \RuntimeException(\sprintf('Corrupt event JSONL line for child run "%s": %s', $this->agentRunId, $e->getMessage()), previous: $e);
        }

        if (!\is_array($payload)) {
            $this->logger->warning('AgentChildRunEventStore skipped non-associative JSONL line', [
                'run_id' => $this->agentRunId,
                'component' => 'agent.artifact',
                'event_type' => 'child_event_store.non_associative_line',
            ]);

            return null;
        }

        $event = $this->eventLog->denormalizeRunEvent($payload);
        if (null === $event) {
            if (!$this->eventLog->isIncompatibleSchemaVersion($payload)) {
                throw new \RuntimeException(\sprintf('Corrupt event JSONL for child run "%s": denormalization returned null for compatible or missing schema', $this->agentRunId));
            }

            $this->logger->debug('Skipping incompatible schema version in child event JSONL', [
                'run_id' => $this->agentRunId,
                'schema_version' => $payload['schema_version'] ?? null,
                'component' => 'agent.artifact',
                'event_type' => 'child_event_store.incompatible_schema',
            ]);

            return null;
        }

        if ($event->runId !== $this->agentRunId) {
            throw new \RuntimeException(\sprintf('RunEvent integrity error at seq %d: embedded runId "%s" does not match bound agentRunId "%s".', $event->seq, $event->runId, $this->agentRunId));
        }

        return $event;
    }

    private function eventsPath(): string
    {
        return $this->pathResolver->eventsPath($this->parentRunId, $this->artifactId);
    }
}
