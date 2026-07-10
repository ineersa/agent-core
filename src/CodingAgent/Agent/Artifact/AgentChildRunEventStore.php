<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunSequenceAllocatorInterface;
use Ineersa\AgentCore\Contract\SequencedEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Schema\SchemaVersion;
use Ineersa\CodingAgent\Session\EventLogMaxSeqBootstrapReader;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Parent-scoped EventStoreInterface implementation for child agent runs.
 */
final class AgentChildRunEventStore implements SequencedEventStoreInterface
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

    public function append(RunEvent $event): void
    {
        if ($event->runId !== $this->agentRunId) {
            throw new \RuntimeException(\sprintf('RunEvent integrity error: embedded runId "%s" does not match bound agentRunId "%s".', $event->runId, $this->agentRunId));
        }

        $path = $this->eventsPath();
        $lock = $this->lockFactory->createLock("hatfield-run-{$this->agentRunId}");
        $lock->acquire(true);

        try {
            $this->writeEventLocked($path, $event);
        } finally {
            $lock->release();
        }
    }

    public function appendWithNextSeq(RunEvent $event): RunEvent
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

    public function appendManyWithNextSeq(array $events): array
    {
        if ([] === $events) {
            return [];
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
                if ($event->runId !== $this->agentRunId) {
                    throw new \RuntimeException(\sprintf('RunEvent integrity error: embedded runId "%s" does not match bound agentRunId "%s".', $event->runId, $this->agentRunId));
                }

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

    public function appendMany(array $events): void
    {
        foreach ($events as $event) {
            $this->append($event);
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
                throw new \RuntimeException(\sprintf('Corrupt event JSONL line for child run "%s": %s', $this->agentRunId, $e->getMessage()), previous: $e);
            }

            if (!\is_array($payload)) {
                $this->logger->warning('AgentChildRunEventStore skipped non-associative JSONL line', [
                    'run_id' => $this->agentRunId,
                    'line' => mb_substr($trimmedLine, 0, 200),
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
                    'schema_version' => $payload['schema_version'],
                ]);

                continue;
            }

            if ($event->runId !== $this->agentRunId) {
                throw new \RuntimeException(\sprintf('RunEvent integrity error at seq %d: embedded runId "%s" does not match bound agentRunId "%s".', $event->seq, $event->runId, $this->agentRunId));
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
            mkdir($dir, AgentArtifactPathResolver::DIR_PERMISSIONS, true);
        }

        $entry = $this->eventPayloadNormalizer->normalizeRunEvent($event);
        $json = json_encode($entry, \JSON_THROW_ON_ERROR);

        $written = file_put_contents($path, $json."\n", \FILE_APPEND | \LOCK_EX);
        if (false === $written) {
            throw new \RuntimeException(\sprintf('Failed to append to events.jsonl for child run "%s".', $this->agentRunId));
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
