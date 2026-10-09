<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Session\Contract\RunSequenceAllocatorInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * File-backed EventStoreInterface implementation.
 *
 * Stores RunEvent entries as append-only JSONL at
 * .hatfield/sessions/<runId>/events.jsonl.
 *
 * Sequence allocation uses a per-run {@see FileRunSequenceAllocator::COUNTER_BASENAME} file.
 * events.jsonl is never scanned during normal prepared transition append (only bootstrap when cursor is missing).
 *
 * Append/sequence/bootstrap mechanics and the decode/denormalize/schema/sort
 * primitives are delegated to {@see JsonlRunEventLog}; this class owns the
 * session path resolution and read diagnostics.
 *
 * Canonical reads stream physical JSONL lines. allFor() still returns the complete
 * decoded event list required by the current API, but it no longer duplicates the
 * whole file text via file_get_contents()+explode(). There is no process-local
 * decoded snapshot cache: compaction and other writers can mutate the file outside
 * this process, and retaining every decoded body after resume kept obsolete
 * pre-compaction payloads hot for the TUI lifetime.
 */
final class SessionRunEventStore implements \Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface
{
    private readonly string $sessionsBasePath;
    private readonly JsonlRunEventLog $eventLog;

    public function __construct(
        HatfieldSessionStore $hatfieldSessionStore,
        EventPayloadNormalizer $eventPayloadNormalizer,
        LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        RunSequenceAllocatorInterface $sequenceAllocator,
        EventLogMaxSeqBootstrapReader $bootstrapReader = new EventLogMaxSeqBootstrapReader(),
    ) {
        $this->sessionsBasePath = $hatfieldSessionStore->resolveSessionsBasePath();
        $this->eventLog = new JsonlRunEventLog($eventPayloadNormalizer, $lockFactory, $sequenceAllocator, $bootstrapReader, $logger);
    }

    public function appendTransition(array $events, array $work): array
    {
        if ([] === $events) {
            if (!\is_string($work['run_id'] ?? null)) {
                throw new \InvalidArgumentException('Prepared decision requires run identity.');
            }
        }
        foreach ($events as $event) {
            if ($event->runId !== $events[0]->runId) {
                throw new \InvalidArgumentException('Transition events have inconsistent run identity.');
            }
        }

        return $this->eventLog->appendMany($this->eventsPath($events[0]->runId ?? $work['run_id']), $events, work: $work);
    }

    public function verifiedPendingTransition(string $runId): ?\Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO
    {
        return $this->eventLog->verifiedPendingTransition($this->eventsPath($runId), $runId);
    }

    public function verifiedPendingBatch(string $runId, string $identity): array
    {
        return $this->eventLog->verifiedPendingBatch($this->eventsPath($runId), $runId, $identity);
    }

    public function finalizeVerifiedTransition(string $runId, string $identity): void
    {
        $this->eventLog->finalizeVerifiedTransition($this->eventsPath($runId), $runId, $identity);
    }

    public function assertTransitionReady(string $runId): void
    {
        $this->eventLog->assertTransitionReady($this->eventsPath($runId), $runId);
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
        foreach ($this->streamDecodedEvents($runId, 'firstFor') as $event) {
            return $event;
        }

        return null;
    }

    /**
     * Streams events one JSONL line at a time without materializing the full allFor list.
     *
     * Reads indexed physical locations within the committed cut. Missing or invalid
     * disposable indexes first rebuild scalar metadata in one streaming pass.
     *
     * @return \Generator<int, RunEvent>
     */
    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        if ($startSeq < 1 || $endSeq < $startSeq) {
            return;
        }

        foreach ($this->eventLog->indexedLines($this->eventsPath($runId), $runId, $startSeq, $endSeq) as $line) {
            $event = $this->eventFromLine($runId, $line);
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
        $path = $this->eventsPath($runId);
        $observation = new JsonlPhysicalReadObservation();
        $decodedEventCount = 0;

        try {
            foreach ($this->eventLog->reverseLines($path, $observation) as $line) {
                $event = $this->eventFromLine($runId, $line);
                if (null === $event) {
                    continue;
                }

                ++$decodedEventCount;
                yield $event;
            }
        } finally {
            $this->logPhysicalRead($runId, 'reverseFor', $observation, $decodedEventCount);
        }
    }

    /**
     * @return list<RunEvent>
     */
    public function allFor(string $runId): array
    {
        $events = [];
        foreach ($this->streamDecodedEvents($runId, 'allFor') as $event) {
            $events[] = $event;
        }

        return $this->eventLog->sortBySeq($events);
    }

    /**
     * @return \Generator<int, RunEvent>
     */
    private function streamDecodedEvents(string $runId, string $method): \Generator
    {
        $path = $this->eventsPath($runId);
        $observation = new JsonlPhysicalReadObservation();
        $decodedEventCount = 0;

        try {
            foreach ($this->eventLog->forwardLines($path, $observation) as $line) {
                $event = $this->eventFromLine($runId, $line);
                if (null === $event) {
                    continue;
                }

                ++$decodedEventCount;
                yield $event;
            }
        } finally {
            $this->logPhysicalRead($runId, $method, $observation, $decodedEventCount);
        }
    }

    /**
     * @param non-empty-string $method
     */
    private function logPhysicalRead(
        string $runId,
        string $method,
        JsonlPhysicalReadObservation $observation,
        int $decodedEventCount,
    ): void {
        $this->logger->debug('Session event store physical JSONL read', [
            'run_id' => $runId,
            'component' => 'session.event_store',
            'event_type' => 'session.event_store.physical_read',
            'method' => $method,
            'archive_bytes_read' => $observation->archiveBytesRead(),
            'lines_yielded' => $observation->linesYielded(),
            'decoded_event_count' => $decodedEventCount,
            'full_scan' => $observation->fullScan(),
            'early_exit' => $observation->earlyExit(),
            'reached_eof' => $observation->reachedEof(),
        ]);
    }

    private function eventFromLine(string $runId, string $line): ?RunEvent
    {
        $trimmedLine = trim($line);
        if ('' === $trimmedLine) {
            return null;
        }

        try {
            $payload = $this->eventLog->decodeLine($trimmedLine);
        } catch (\JsonException $e) {
            throw new \RuntimeException(\sprintf('Corrupt event JSONL line for run "%s" — not parseable as JSON: %s', $runId, $e->getMessage()), previous: $e);
        }

        if (!\is_array($payload)) {
            $this->logger->warning('SessionRunEventStore skipped non-associative JSONL line', [
                'run_id' => $runId,
                'line' => mb_substr($trimmedLine, 0, 200),
            ]);

            return null;
        }

        $event = $this->eventLog->denormalizeRunEvent($payload);
        if (null === $event) {
            if (!$this->eventLog->isIncompatibleSchemaVersion($payload)) {
                throw new \RuntimeException(\sprintf('Corrupt event JSONL for run "%s": denormalization returned null for compatible or missing schema — line: %s', $runId, mb_substr($trimmedLine, 0, 200)));
            }

            $this->logger->error('Skipping incompatible schema version in event JSONL', [
                'run_id' => $runId,
                'schema_version' => $payload['schema_version'] ?? null,
                'component' => 'session.event_store',
                'event_type' => 'session.incompatible_schema_skipped',
            ]);

            return null;
        }

        if ($event->runId !== $runId) {
            throw new \RuntimeException(\sprintf('RunEvent integrity error at seq %d: embedded runId "%s" does not match directory "%s".', $event->seq, $event->runId, $runId));
        }

        return $event;
    }

    private function eventsPath(string $runId): string
    {
        return $this->sessionsBasePath.'/'.$runId.'/events.jsonl';
    }
}
