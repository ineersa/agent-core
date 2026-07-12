<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Schema\SchemaVersion;
use Ineersa\CodingAgent\Runtime\Contract\CommittedEventStoreInterface;
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
 */
final class SessionRunEventStore implements CommittedEventStoreInterface
{
    private readonly string $sessionsBasePath;

    public function __construct(
        HatfieldSessionStore $hatfieldSessionStore,
        private readonly EventPayloadNormalizer $eventPayloadNormalizer,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly RunSequenceAllocatorInterface $sequenceAllocator,
        private readonly EventLogMaxSeqBootstrapReader $bootstrapReader = new EventLogMaxSeqBootstrapReader(),
    ) {
        $this->sessionsBasePath = $hatfieldSessionStore->resolveSessionsBasePath();
    }

    public function append(RunEvent $event): RunEvent
    {
        $path = $this->eventsPath($event->runId);
        $lock = $this->lockFactory->createLock('hatfield-run-'.$event->runId);
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

        $runId = $events[0]->runId;
        foreach ($events as $event) {
            if ($event->runId !== $runId) {
                throw new \InvalidArgumentException('appendMany requires all events to share the same runId.');
            }
        }

        $path = $this->eventsPath($runId);
        $lock = $this->lockFactory->createLock('hatfield-run-'.$runId);
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
     * @return list<RunEvent>
     */
    public function allFor(string $runId): array
    {
        $path = $this->eventsPath($runId);

        if (!is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);
        if (false === $contents) {
            return [];
        }

        $events = [];

        foreach (explode("\n", $contents) as $line) {
            $trimmedLine = trim($line);
            if ('' === $trimmedLine) {
                continue;
            }

            try {
                $payload = json_decode($trimmedLine, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException(\sprintf('Corrupt event JSONL line for run "%s" — not parseable as JSON: %s', $runId, $e->getMessage()), previous: $e);
            }

            if (!\is_array($payload)) {
                $this->logger->warning('SessionRunEventStore skipped non-associative JSONL line', [
                    'run_id' => $runId,
                    'line' => mb_substr($trimmedLine, 0, 200),
                ]);

                continue;
            }

            $event = $this->eventPayloadNormalizer->denormalizeRunEvent($payload);
            if (null === $event) {
                if (!$this->isIncompatibleSchemaVersion($payload)) {
                    throw new \RuntimeException(\sprintf('Corrupt event JSONL for run "%s": denormalization returned null for compatible or missing schema — line: %s', $runId, mb_substr($trimmedLine, 0, 200)));
                }

                $this->logger->error('Skipping incompatible schema version in event JSONL', [
                    'run_id' => $runId,
                    'schema_version' => $payload['schema_version'] ?? null,
                    'component' => 'session.event_store',
                    'event_type' => 'session.incompatible_schema_skipped',
                ]);

                continue;
            }

            if ($event->runId !== $runId) {
                throw new \RuntimeException(\sprintf('RunEvent integrity error at seq %d: embedded runId "%s" does not match directory "%s".', $event->seq, $event->runId, $runId));
            }

            $events[] = $event;
        }

        usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        return $events;
    }

    private function writeEventLocked(string $path, RunEvent $event): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $entry = $this->eventPayloadNormalizer->normalizeRunEvent($event);
        $json = json_encode($entry, \JSON_THROW_ON_ERROR);

        $written = file_put_contents($path, $json."\n", \FILE_APPEND | \LOCK_EX);
        if (false === $written) {
            throw new \RuntimeException(\sprintf('Failed to append to events.jsonl for run "%s" at seq %d.', $event->runId, $event->seq));
        }
    }

    /**
     * Major schema version mismatch: skip line with error log (forward-compat read policy).
     *
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

    private function eventsPath(string $runId): string
    {
        return $this->sessionsBasePath.'/'.$runId.'/events.jsonl';
    }
}
