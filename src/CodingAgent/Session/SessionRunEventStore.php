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
 * events.jsonl is never scanned during normal append (only bootstrap when cursor is missing).
 *
 * Append/sequence/bootstrap mechanics and the decode/denormalize/schema/sort
 * primitives are delegated to {@see JsonlRunEventLog}; this class owns the
 * session path resolution and read diagnostics.
 *
 * allFor() always returns the complete canonical stream from disk. There is no
 * process-local decoded snapshot cache: compaction and other writers can mutate
 * the file outside this process, and retaining every decoded body after resume
 * kept obsolete pre-compaction payloads hot for the TUI lifetime.
 */
final class SessionRunEventStore implements EventStoreInterface
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
        $this->eventLog = new JsonlRunEventLog($eventPayloadNormalizer, $lockFactory, $sequenceAllocator, $bootstrapReader);
    }

    public function append(RunEvent $event): RunEvent
    {
        $path = $this->eventsPath($event->runId);

        return $this->eventLog->appendMany($path, events: [$event])[0];
    }

    public function appendMany(array $events): array
    {
        if ([] === $events) {
            return [];
        }

        $runId = $events[0]->runId;
        foreach ($events as $event) {
            if ($event->runId !== $runId) {
                throw new \InvalidArgumentException('appendMany requires all events to share the same runId.');
            }
        }

        $path = $this->eventsPath($runId);

        return $this->eventLog->appendMany($path, $events);
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
        $path = $this->eventsPath($runId);
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return null;
        }

        try {
            while (false !== ($line = fgets($handle))) {
                $event = $this->eventFromLine($runId, $line);
                if (null !== $event) {
                    return $event;
                }
            }

            return null;
        } finally {
            fclose($handle);
        }
    }

    /**
     * Streams events one JSONL line at a time without materializing the full allFor list.
     *
     * Events are physically appended under the per-run sequence lock, so durable file order
     * is canonical sequence order (with possible sequence holes). The scan stops at the first
     * sequence above endSeq; allFor() remains responsible for full-log validation.
     *
     * @return \Generator<int, RunEvent>
     */
    public function rangeFor(string $runId, int $startSeq, int $endSeq): iterable
    {
        if ($startSeq < 1 || $endSeq < $startSeq) {
            return;
        }

        $handle = @fopen($this->eventsPath($runId), 'rb');
        if (false === $handle) {
            return;
        }

        try {
            while (false !== ($line = fgets($handle))) {
                $event = $this->eventFromLine($runId, $line);
                if (null === $event) {
                    continue;
                }

                if ($event->seq > $endSeq) {
                    break;
                }

                if ($event->seq >= $startSeq) {
                    yield $event;
                }
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return \Generator<int, RunEvent>
     */
    public function reverseFor(string $runId): iterable
    {
        foreach ($this->eventLog->reverseLines($this->eventsPath($runId)) as $line) {
            $event = $this->eventFromLine($runId, $line);
            if (null !== $event) {
                yield $event;
            }
        }
    }

    /**
     * @return list<RunEvent>
     */
    public function allFor(string $runId): array
    {
        $path = $this->eventsPath($runId);
        $contents = @file_get_contents($path);
        if (false === $contents) {
            return [];
        }

        $events = [];

        foreach (explode("\n", $contents) as $line) {
            $event = $this->eventFromLine($runId, $line);
            if (null !== $event) {
                $events[] = $event;
            }
        }

        return $this->eventLog->sortBySeq($events);
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
