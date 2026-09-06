<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Schema\SchemaVersion;
use Ineersa\CodingAgent\Session\Contract\RunSequenceAllocatorInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Shared JSONL run-event log engine for session and child-run event stores.
 *
 * Owns the mechanics duplicated across {@see SessionRunEventStore} and
 * {@see AgentChildRunEventStore}: per-run Symfony lock acquisition/release,
 * sequence allocation with bootstrap, persisted RunEvent reconstruction,
 * canonical normalize+encode+append, JSON line decoding, denormalization,
 * the schema-major compatibility check, and seq sorting.
 *
 * Callers keep their own read/cache/logging/validation policies (whole-file
 * vs streaming reads, size+mtime caching, per-store exception/log messages).
 * The only policy hook is the optional successful-write callback used by
 * SessionRunEventStore for cache invalidation.
 *
 * @internal
 */
final class JsonlRunEventLog
{
    public function __construct(
        private readonly EventPayloadNormalizer $eventPayloadNormalizer,
        private readonly LockFactory $lockFactory,
        private readonly RunSequenceAllocatorInterface $sequenceAllocator,
        private readonly EventLogMaxSeqBootstrapReader $bootstrapReader,
    ) {
    }

    /**
     * Allocates a contiguous seq block and appends already-validated events under the run lock.
     *
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    public function appendMany(
        string $path,
        array $events,
        string $runLabel = 'run',
        ?int $dirMode = null,
    ): array {
        $lock = $this->lockFactory->createLock('hatfield-run-'.$events[0]->runId);
        $lock->acquire(true);

        try {
            $seqBlock = $this->sequenceAllocator->allocateBlock(
                FileRunSequenceAllocator::counterPathForEventsLog($path),
                \count($events),
                fn (): int => $this->bootstrapReader->readMaxSeq($path),
            );
            $persisted = [];

            foreach ($events as $index => $event) {
                $persistedEvent = $this->withSeq($event, $seqBlock[$index]);
                $this->writeEventLocked($path, $persistedEvent, $runLabel, $dirMode);
                $persisted[] = $persistedEvent;
            }

            return $persisted;
        } finally {
            $lock->release();
        }
    }

    /**
     * Streams non-empty lines from the file tail toward its head. A partial final
     * line is yielded unchanged so callers preserve their normal corruption policy.
     *
     * @return \Generator<int, string>
     */
    public function reverseLines(string $path): iterable
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            return;
        }

        try {
            $size = filesize($path);
            if (false === $size || 0 === $size) {
                return;
            }

            $position = $size;
            $tail = '';
            while ($position > 0) {
                $length = min(8192, $position);
                $position -= $length;
                fseek($handle, $position);
                $chunk = fread($handle, $length);
                if (false === $chunk) {
                    return;
                }

                $tail = $chunk.$tail;
                $lines = explode("\n", $tail);
                $tail = array_shift($lines);
                for ($index = \count($lines) - 1; $index >= 0; --$index) {
                    $line = rtrim($lines[$index], "\r");
                    if ('' !== trim($line)) {
                        yield $line;
                    }
                }
            }

            if ('' !== trim($tail)) {
                yield $tail;
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Decodes one JSONL line. Throws {@see \JsonException} on malformed JSON.
     *
     * @return mixed decoded value; callers decide how to treat non-associative lines
     */
    public function decodeLine(string $line): mixed
    {
        return json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function denormalizeRunEvent(array $payload): ?RunEvent
    {
        return $this->eventPayloadNormalizer->denormalizeRunEvent($payload);
    }

    /**
     * Major schema version mismatch: skip line with a per-store diagnostic (forward-compat read policy).
     *
     * @param array<string, mixed> $payload
     */
    public function isIncompatibleSchemaVersion(array $payload): bool
    {
        $schemaVersion = $payload['schema_version'] ?? null;
        if (!\is_string($schemaVersion)) {
            return false;
        }

        $expectedMajor = explode('.', SchemaVersion::CURRENT, 2)[0];
        $candidateMajor = explode('.', $schemaVersion, 2)[0];

        return '' !== $candidateMajor && $candidateMajor !== $expectedMajor;
    }

    /**
     * @param list<RunEvent> $events
     *
     * @return list<RunEvent>
     */
    public function sortBySeq(array $events): array
    {
        usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        return $events;
    }

    private function withSeq(RunEvent $event, int $seq): RunEvent
    {
        return new RunEvent(
            runId: $event->runId,
            seq: $seq,
            turnNo: $event->turnNo,
            type: $event->type,
            payload: $event->payload,
            createdAt: $event->createdAt,
        );
    }

    private function writeEventLocked(string $path, RunEvent $event, string $runLabel, ?int $dirMode): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            if (null === $dirMode) {
                mkdir(directory: $dir, recursive: true);
            } else {
                mkdir($dir, $dirMode, true);
            }
        }

        $entry = $this->eventPayloadNormalizer->normalizeRunEvent($event);
        $json = json_encode($entry, \JSON_THROW_ON_ERROR);

        $written = file_put_contents($path, $json."\n", \FILE_APPEND | \LOCK_EX);
        if (false === $written) {
            throw new \RuntimeException(\sprintf('Failed to append to events.jsonl for %s "%s" at seq %d.', $runLabel, $event->runId, $event->seq));
        }
    }
}
