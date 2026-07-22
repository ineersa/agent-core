<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Schema\SchemaVersion;
use Ineersa\CodingAgent\Session\Contract\RunSequenceAllocatorInterface;
use Ineersa\CodingAgent\Session\EventLogMaxSeqBootstrapReader;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Parent-scoped EventStoreInterface implementation for child agent runs.
 *
 * Writes and reads RunEvent entries at the parent-scoped artifact path:
 *
 *   .hatfield/sessions/<parentRunId>/artifacts/agents/<artifactId>/events.jsonl
 *
 * Sequence allocation uses {@see FileRunSequenceAllocator::COUNTER_BASENAME} next to that log.
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
 * {@see AgentArtifactPathResolver}.
 */
final class AgentChildRunEventStore implements EventStoreInterface
{
    public function __construct(
        private readonly AgentArtifactPathResolver $pathResolver,
        private readonly EventPayloadNormalizer $eventPayloadNormalizer,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly RunSequenceAllocatorInterface $sequenceAllocator,
        private readonly string $parentRunId,
        private readonly string $agentRunId,
        private readonly string $artifactId,
        private readonly EventLogMaxSeqBootstrapReader $bootstrapReader = new EventLogMaxSeqBootstrapReader(),
    ) {
        $this->pathResolver->validatePathComponent($parentRunId, 'parentRunId');
        $this->pathResolver->validatePathComponent($artifactId, 'artifactId');
    }

    public function append(RunEvent $event): RunEvent
    {
        if ($event->runId !== $this->agentRunId) {
            throw new \RuntimeException(\sprintf('RunEvent integrity error: embedded runId "%s" does not match bound agentRunId "%s".', $event->runId, $this->agentRunId));
        }

        $path = $this->eventsPath();
        $lock = $this->lockFactory->createLock("hatfield-run-{$this->agentRunId}");
        $lock->acquire(true);

        try {
            $counterPath = FileRunSequenceAllocator::counterPathForEventsLog($path);
            $nextSeq = $this->sequenceAllocator->allocateNext(
                $counterPath,
                fn (): int => $this->bootstrapReader->readMaxSeq($path),
            );
            $persisted = new RunEvent(
                runId: $event->runId,
                seq: $nextSeq,
                turnNo: $event->turnNo,
                type: $event->type,
                payload: $event->payload,
                createdAt: $event->createdAt,
            );
            $this->writeEventLocked($path, $persisted);

            return $persisted;
        } finally {
            $lock->release();
        }
    }

    public function appendMany(array $events): array
    {
        if ([] === $events) {
            return [];
        }

        foreach ($events as $event) {
            if ($event->runId !== $this->agentRunId) {
                throw new \RuntimeException(\sprintf('RunEvent integrity error: embedded runId "%s" does not match bound agentRunId "%s".', $event->runId, $this->agentRunId));
            }
        }

        $path = $this->eventsPath();
        $lock = $this->lockFactory->createLock("hatfield-run-{$this->agentRunId}");
        $lock->acquire(true);

        try {
            $counterPath = FileRunSequenceAllocator::counterPathForEventsLog($path);
            $seqBlock = $this->sequenceAllocator->allocateBlock(
                $counterPath,
                \count($events),
                fn (): int => $this->bootstrapReader->readMaxSeq($path),
            );
            $persisted = [];

            foreach ($events as $index => $event) {
                $persistedEvent = new RunEvent(
                    runId: $event->runId,
                    seq: $seqBlock[$index],
                    turnNo: $event->turnNo,
                    type: $event->type,
                    payload: $event->payload,
                    createdAt: $event->createdAt,
                );
                $this->writeEventLocked($path, $persistedEvent);
                $persisted[] = $persistedEvent;
            }

            return $persisted;
        } finally {
            $lock->release();
        }
    }

    /**
     * Recovery-only tail read of durable child events.jsonl (not for steady-state supervision).
     *
     * @return list<RunEvent> Events with seq > $cursor, sorted ascending. Sequence holes are preserved.
     */
    public function readAfterSeq(int $cursor): array
    {
        $path = $this->eventsPath();
        $lock = $this->lockFactory->createLock("hatfield-run-{$this->agentRunId}");
        $lock->acquire(true);

        try {
            $events = [];
            foreach ($this->streamRunEventsFromPath($path) as $event) {
                if ($event->seq <= $cursor) {
                    continue;
                }
                $events[] = $event;
            }

            usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

            return $events;
        } finally {
            $lock->release();
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

        $path = $this->eventsPath();
        if (!is_readable($path)) {
            return [];
        }

        $events = iterator_to_array($this->streamRunEventsFromPath($path));
        usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        return $events;
    }

    /**
     * @return \Generator<int, RunEvent>
     */
    private function streamRunEventsFromPath(string $path): \Generator
    {
        if (!is_readable($path)) {
            return;
        }

        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmedLine = trim($line);
                if ('' === $trimmedLine) {
                    continue;
                }

                try {
                    $payload = json_decode($trimmedLine, true, 512, \JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    throw new \RuntimeException(\sprintf('Corrupt event JSONL line for child run "%s": %s', $this->agentRunId, $e->getMessage()), previous: $e);
                }

                if (!\is_array($payload)) {
                    $this->logger->warning('AgentChildRunEventStore skipped non-associative JSONL line', [
                        'run_id' => $this->agentRunId,
                        'component' => 'agent.artifact',
                        'event_type' => 'child_event_store.non_associative_line',
                    ]);

                    continue;
                }

                $event = $this->eventPayloadNormalizer->denormalizeRunEvent($payload);
                if (null === $event) {
                    if (!$this->isIncompatibleSchemaVersion($payload)) {
                        throw new \RuntimeException(\sprintf('Corrupt event JSONL for child run "%s": denormalization returned null for compatible or missing schema', $this->agentRunId));
                    }

                    $this->logger->debug('Skipping incompatible schema version in child event JSONL', [
                        'run_id' => $this->agentRunId,
                        'schema_version' => $payload['schema_version'] ?? null,
                        'component' => 'agent.artifact',
                        'event_type' => 'child_event_store.incompatible_schema',
                    ]);

                    continue;
                }

                if ($event->runId !== $this->agentRunId) {
                    throw new \RuntimeException(\sprintf('RunEvent integrity error at seq %d: embedded runId "%s" does not match bound agentRunId "%s".', $event->seq, $event->runId, $this->agentRunId));
                }

                yield $event;
            }
        } finally {
            fclose($handle);
        }
    }

    private function writeEventLocked(string $path, RunEvent $event): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, AgentArtifactPathResolver::DIR_PERMISSIONS, true);
        }

        $entry = $this->eventPayloadNormalizer->normalizeRunEvent($event);
        $json = json_encode($entry, \JSON_THROW_ON_ERROR);

        $written = file_put_contents($path, $json."\n", \FILE_APPEND | \LOCK_EX);
        if (false === $written) {
            throw new \RuntimeException(\sprintf('Failed to append to events.jsonl for child run "%s" at seq %d.', $this->agentRunId, $event->seq));
        }
    }

    private function eventsPath(): string
    {
        return $this->pathResolver->eventsPath($this->parentRunId, $this->artifactId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isIncompatibleSchemaVersion(array $payload): bool
    {
        $schemaVersion = $payload['schema_version'] ?? null;
        if (!\is_string($schemaVersion)) {
            return false;
        }

        $expectedMajor = explode('.', SchemaVersion::CURRENT, 2)[0];
        $candidateMajor = explode('.', $schemaVersion, 2)[0];

        return '' !== $candidateMajor && $candidateMajor !== $expectedMajor;
    }
}
