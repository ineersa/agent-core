<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\AgentCore\Contract\CursorAwareEventStoreInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Schema\SchemaVersion;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * File-backed EventStoreInterface implementation.
 *
 * Stores RunEvent entries as append-only JSONL at
 * .hatfield/sessions/<runId>/events.jsonl.
 *
 * Uses Symfony Lock (FlockStore) to protect concurrent appends.
 * Reuses EventPayloadNormalizer for canonical event serialization.
 *
 * Directory name is canonical; embedded runId in each event
 * must match. Mismatches throw on read.
 *
 * Uses HatfieldSessionStore::resolveSessionsBasePath() as the single
 * source of truth for the sessions directory.
 */
final class SessionRunEventStore implements CursorAwareEventStoreInterface
{
    private readonly string $sessionsBasePath;

    public function __construct(
        HatfieldSessionStore $hatfieldSessionStore,
        private readonly EventPayloadNormalizer $eventPayloadNormalizer,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
        $this->sessionsBasePath = $hatfieldSessionStore->resolveSessionsBasePath();
    }

    public function append(RunEvent $event): void
    {
        $path = $this->eventsPath($event->runId);
        $lock = $this->lockFactory->createLock('hatfield-run-'.$event->runId);
        $lock->acquire(true);

        try {
            $dir = \dirname($path);
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }

            $entry = $this->eventPayloadNormalizer->normalizeRunEvent($event);
            $json = json_encode($entry, \JSON_THROW_ON_ERROR);

            file_put_contents($path, $json."\n", \FILE_APPEND | \LOCK_EX);
        } finally {
            $lock->release();
        }
    }

    public function appendMany(array $events): void
    {
        foreach ($events as $event) {
            $this->append($event);
        }
    }

    /**
     * @return iterable<int, RunEvent>
     */
    public function allForAfter(string $runId, int $afterSeq): iterable
    {
        $path = $this->eventsPath($runId);

        if (!is_readable($path)) {
            return;
        }

        $file = new \SplFileObject($path, 'r');
        $file->setFlags(\SplFileObject::DROP_NEW_LINE | \SplFileObject::READ_AHEAD);

        foreach ($file as $line) {
            if (!\is_string($line)) {
                continue;
            }

            $trimmedLine = trim($line);
            if ('' === $trimmedLine) {
                continue;
            }

            $event = $this->denormalizeLine($runId, $trimmedLine);
            if (null === $event) {
                continue;
            }

            if ($event->seq <= $afterSeq) {
                continue;
            }

            yield $event;
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

            $event = $this->denormalizeLine($runId, $trimmedLine);
            if (null === $event) {
                continue;
            }

            $events[] = $event;
        }

        usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        return $events;
    }

    private function denormalizeLine(string $runId, string $trimmedLine): ?RunEvent
    {
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

            return null;
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

            return null;
        }

        if ($event->runId !== $runId) {
            throw new \RuntimeException(\sprintf('RunEvent integrity error at seq %d: embedded runId "%s" does not match directory "%s".', $event->seq, $event->runId, $runId));
        }

        return $event;
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

    private function sessionsDir(): string
    {
        return $this->sessionsBasePath;
    }

    private function eventsPath(string $runId): string
    {
        return $this->sessionsDir().'/'.$runId.'/events.jsonl';
    }
}
