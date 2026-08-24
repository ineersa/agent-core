<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Session\Contract\RunSequenceAllocatorInterface;
use Ineersa\CodingAgent\Session\EventLogMaxSeqBootstrapReader;
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
final class AgentChildRunEventStore implements EventStoreInterface
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
        $this->eventLog = new JsonlRunEventLog($eventPayloadNormalizer, $lockFactory, $sequenceAllocator, $bootstrapReader);
    }

    public function append(RunEvent $event): RunEvent
    {
        if ($event->runId !== $this->agentRunId) {
            throw new \RuntimeException(\sprintf('RunEvent integrity error: embedded runId "%s" does not match bound agentRunId "%s".', $event->runId, $this->agentRunId));
        }

        return $this->eventLog->appendMany(
            path: $this->eventsPath(),
            events: [$event],
            runLabel: 'child run',
            dirMode: SessionAgentArtifactPathResolver::DIR_PERMISSIONS,
        )[0];
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

        return $this->eventLog->appendMany(
            path: $this->eventsPath(),
            events: $events,
            runLabel: 'child run',
            dirMode: SessionAgentArtifactPathResolver::DIR_PERMISSIONS,
        );
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

            return $this->eventLog->sortBySeq($events);
        } finally {
            $lock->release();
        }
    }

    public function latestSequenceFor(string $runId): ?int
    {
        $events = $this->allFor($runId);

        return [] === $events ? null : $events[array_key_last($events)]->seq;
    }

    public function firstFor(string $runId): ?RunEvent
    {
        if ($runId !== $this->agentRunId) {
            return null;
        }

        foreach ($this->streamRunEventsFromPath($this->eventsPath()) as $event) {
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

        foreach ($this->streamRunEventsFromPath($this->eventsPath()) as $event) {
            if ($event->seq > $endSeq) {
                break;
            }

            if ($event->seq >= $startSeq) {
                yield $event;
            }
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

        return $this->eventLog->sortBySeq($events);
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

                    continue;
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

    private function eventsPath(): string
    {
        return $this->pathResolver->eventsPath($this->parentRunId, $this->artifactId);
    }
}
