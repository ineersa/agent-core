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
 * vs streaming reads, optional physical-read observations, per-store exception/log messages).
 * The only policy hook is the optional successful-write callback used by
 * SessionRunEventStore for cache invalidation.
 * Canonical publication finalizes only through verified transition identity.
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
     * @param list<RunEvent>       $events
     * @param array<string, mixed> $work
     *
     * @return list<RunEvent>
     */
    public function appendMany(
        string $path,
        array $events,
        string $runLabel = 'run',
        ?int $dirMode = null,
        array $work = [],
    ): array {
        $runId = $events[0]->runId ?? $work['run_id'] ?? null;
        if (!\is_string($runId) || '' === $runId) {
            throw new \InvalidArgumentException('Prepared transition requires run identity.');
        }
        $lock = $this->lockFactory->createLock('hatfield-run-'.$runId);
        $lock->acquire(true);

        try {
            (new JsonlAppendJournal())->assertReady($path);
            if ([] === $work) {
                throw new \InvalidArgumentException('Canonical append requires captured owner coordination work.');
            }
            $predecessorSeq = 0;
            foreach ($this->reverseLines($path) as $line) {
                $predecessor = $this->decodeLine($line);
                if (!\is_array($predecessor) || ($predecessor['run_id'] ?? null) !== $runId || !\is_int($predecessor['seq'] ?? null)) {
                    throw new \RuntimeException('Invalid canonical transition predecessor identity.');
                }
                $predecessorSeq = $predecessor['seq'];
                break;
            }
            if (($work['predecessor_seq'] ?? null) !== $predecessorSeq) {
                throw new \RuntimeException('Canonical transition predecessor sequence changed.');
            }
            $seqBlock = [] === $events ? [] : $this->sequenceAllocator->allocateBlock(
                FileRunSequenceAllocator::counterPathForEventsLog($path),
                \count($events),
                fn (): int => $this->bootstrapReader->readMaxSeq($path),
            );
            $persisted = [];

            foreach ($events as $index => $event) {
                $persistedEvent = $this->withSeq($event, $seqBlock[$index]);
                $persisted[] = $persistedEvent;
            }

            if (null !== $dirMode) {
                (new \Symfony\Component\Filesystem\Filesystem())->mkdir(\dirname($path), $dirMode);
            }
            $records = (function () use ($persisted): iterable {
                foreach ($persisted as $event) {
                    yield json_encode($this->eventPayloadNormalizer->normalizeRunEvent($event), \JSON_THROW_ON_ERROR)."\n";
                }
            })();
            (new JsonlAppendJournal())->append($path, $records, $work);

            return $persisted;
        } finally {
            $lock->release();
        }
    }

    public function verifiedPendingTransition(string $path, string $runId): ?\Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO
    {
        $lock = $this->lockFactory->createLock('hatfield-run-'.$runId);
        $lock->acquire(true);
        try {
            $pending = (new JsonlAppendJournal())->verifiedPending($path);
            if (null !== $pending && ($pending->work['run_id'] ?? null) !== $runId) {
                throw new \RuntimeException('Prepared work run identity mismatch.');
            }

            return $pending;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return list<RunEvent>
     */
    public function verifiedPendingBatch(string $path, string $runId, string $identity): array
    {
        $lock = $this->lockFactory->createLock('hatfield-run-'.$runId);
        $lock->acquire(true);
        try {
            $pending = (new JsonlAppendJournal())->verifiedPending($path);
            if (null === $pending) {
                throw new \RuntimeException('Verified transition missing before batch read.');
            }
            if (($pending->work['run_id'] ?? null) !== $runId) {
                throw new \RuntimeException('Prepared work run identity mismatch.');
            }
            if ($pending->identity !== $identity) {
                throw new \RuntimeException('Prepared transition identity changed before batch read.');
            }

            $records = (new JsonlAppendJournal())->verifiedStagedRecords($path, $identity);
            $events = [];
            foreach ($records as $record) {
                $line = rtrim($record, "\r\n");
                if ('' === $line) {
                    throw new \RuntimeException('Verified staged batch contains a blank record.');
                }
                $payload = $this->decodeLine($line);
                if (!\is_array($payload)) {
                    throw new \RuntimeException('Verified staged batch contains a non-object record.');
                }
                if (($payload['run_id'] ?? null) !== $runId) {
                    throw new \RuntimeException('Verified staged batch run identity mismatch.');
                }
                if ($this->isIncompatibleSchemaVersion($payload)) {
                    throw new \RuntimeException('Verified staged batch schema is incompatible.');
                }
                $event = $this->denormalizeRunEvent($payload);
                if (null === $event) {
                    throw new \RuntimeException('Verified staged batch could not be decoded.');
                }
                $events[] = $event;
            }

            $sequences = $pending->eventSequences;
            if (array_map(static fn (RunEvent $event): int => $event->seq, $events) !== $sequences) {
                throw new \RuntimeException('Verified staged batch sequences do not match captured identities.');
            }

            return $events;
        } finally {
            $lock->release();
        }
    }

    public function finalizeVerifiedTransition(string $path, string $runId, string $identity): void
    {
        $lock = $this->lockFactory->createLock('hatfield-run-'.$runId);
        $lock->acquire(true);
        try {
            (new JsonlAppendJournal())->finalizeVerified($path, $identity);
        } finally {
            $lock->release();
        }
    }

    public function assertTransitionReady(string $path, string $runId): void
    {
        $lock = $this->lockFactory->createLock('hatfield-run-'.$runId);
        $lock->acquire(true);
        try {
            (new JsonlAppendJournal())->assertReady($path);
        } finally {
            $lock->release();
        }
    }

    /**
     * Streams non-empty lines from the file tail toward its head. A partial final
     * line is yielded unchanged so callers preserve their normal corruption policy.
     *
     * When {@see JsonlPhysicalReadObservation} is provided, records bytes returned by
     * underlying fread calls. full_scan means the consumer did not stop early and the
     * scanner reached start-of-file; archive_bytes_read may still include unread prefix
     * bytes from the last fetched chunk after an early stop. Size comes from fstat() on
     * the opened handle (not pathname filesize): a failed handle stat or seek is not a
     * successful empty or completed scan. fread() returning fewer bytes than requested
     * (including an empty string) is also not a completed scan; already-yielded complete
     * lines are kept, and an incomplete trailing prefix is not emitted.
     *
     * @return \Generator<int, string>
     */
    public function reverseLines(string $path, ?JsonlPhysicalReadObservation $observation = null): iterable
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            $observation?->finish(reachedEof: false, earlyExit: false);

            return;
        }

        $earlyExit = true;
        try {
            $stat = fstat($handle);
            if (false === $stat || !\array_key_exists('size', $stat)) {
                $earlyExit = false;
                $observation?->finish(reachedEof: false, earlyExit: false);

                return;
            }

            $size = $stat['size'];
            if (\is_int($size) && is_file($path.'.append.pending.json')) {
                $size = (new JsonlAppendJournal())->readableOffset($path, $size);
            }
            if (!\is_int($size) || $size < 0) {
                $earlyExit = false;
                $observation?->finish(reachedEof: false, earlyExit: false);

                return;
            }

            if (0 === $size) {
                $earlyExit = false;
                $observation?->finish(reachedEof: true, earlyExit: false);

                return;
            }

            $position = $size;
            $tail = '';
            while ($position > 0) {
                $length = min(8192, $position);
                $position -= $length;
                if (-1 === fseek($handle, $position)) {
                    $earlyExit = false;
                    $observation?->finish(reachedEof: false, earlyExit: false);

                    return;
                }
                $chunk = fread($handle, $length);
                if (false === $chunk) {
                    $earlyExit = false;
                    $observation?->finish(reachedEof: false, earlyExit: false);

                    return;
                }

                $bytesRead = \strlen($chunk);
                $observation?->addBytes($bytesRead);
                if ($bytesRead !== $length) {
                    $earlyExit = false;
                    $observation?->finish(reachedEof: false, earlyExit: false);

                    return;
                }

                $tail = $chunk.$tail;
                $lines = explode("\n", $tail);
                $tail = array_shift($lines);
                for ($index = \count($lines) - 1; $index >= 0; --$index) {
                    $line = rtrim($lines[$index], "\r");
                    if ('' !== trim($line)) {
                        $observation?->addLineYielded();
                        yield $line;
                    }
                }
            }

            if ('' !== trim($tail)) {
                $observation?->addLineYielded();
                yield $tail;
            }

            $earlyExit = false;
            // Logical end of a reverse scan is start-of-file (position 0), not feof().
            $observation?->finish(reachedEof: true, earlyExit: false);
        } finally {
            fclose($handle);
            if ($earlyExit) {
                $observation?->finish(reachedEof: false, earlyExit: true);
            }
        }
    }

    /**
     * Streams every physical JSONL line forward without materializing the whole file.
     *
     * Empty lines are yielded unchanged so callers keep their existing blank-line policy.
     * A partial final line is also yielded unchanged. When observation is provided,
     * records bytes returned by fgets. EOF is confirmed with feof(); a false fgets
     * caused by a read error is not labelled as a full scan.
     *
     * @return \Generator<int, string>
     */
    public function forwardLines(string $path, ?JsonlPhysicalReadObservation $observation = null): iterable
    {
        $handle = @fopen($path, 'rb');
        if (false === $handle) {
            $observation?->finish(reachedEof: false, earlyExit: false);

            return;
        }

        $earlyExit = true;
        try {
            $stat = fstat($handle);
            if (false === $stat) {
                throw new \RuntimeException('Cannot inspect canonical reader cut.');
            }
            $cut = (new JsonlAppendJournal())->readableOffset($path, $stat['size']);
            $position = 0;
            while ($position < $cut && false !== ($line = fgets($handle, $cut - $position + 1))) {
                $position += \strlen($line);
                $observation?->addBytes(\strlen($line));
                $observation?->addLineYielded();
                yield $line;
            }

            $earlyExit = false;
            $observation?->finish(reachedEof: $position === $cut, earlyExit: false);
        } finally {
            fclose($handle);
            if ($earlyExit) {
                $observation?->finish(reachedEof: false, earlyExit: true);
            }
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
}
