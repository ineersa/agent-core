<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\AgentCore\Contract\CursorAwareEventStoreInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Schema\SchemaVersion;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Parent-scoped EventStoreInterface implementation for child agent runs.
 *
 * Writes and reads RunEvent entries at the parent-scoped artifact path:
 *
 *   .hatfield/sessions/<parentRunId>/artifacts/agents/<artifactId>/events.jsonl
 *
 * Uses Symfony Lock (FlockStore) keyed by the child agentRunId to
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
final class AgentChildRunEventStore implements CursorAwareEventStoreInterface
{
    public function __construct(
        private readonly AgentArtifactPathResolver $pathResolver,
        private readonly EventPayloadNormalizer $eventPayloadNormalizer,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        /** Parent session run ID. */
        private readonly string $parentRunId,
        /** Child agent run ID (embedded in RunEvent->runId). */
        private readonly string $agentRunId,
        /** Artifact directory name within artifacts/agents/. */
        private readonly string $artifactId,
    ) {
        // Defense-in-depth path validation: reject traversal/spurious components.
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
        if ($runId !== $this->agentRunId) {
            return;
        }

        $path = $this->eventsPath();

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

            $event = $this->denormalizeLine($trimmedLine);
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
     * Retrieve all events for the bound child agent run.
     *
     * When $runId differs from the bound agentRunId, returns an empty
     * list — this store is only responsible for its one child.
     *
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

            $event = $this->denormalizeLine($trimmedLine);
            if (null === $event) {
                continue;
            }

            $events[] = $event;
        }

        usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        return $events;
    }

    private function denormalizeLine(string $trimmedLine): ?RunEvent
    {
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

            return null;
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

            return null;
        }

        if ($event->runId !== $this->agentRunId) {
            throw new \RuntimeException(\sprintf('RunEvent integrity error at seq %d: embedded runId "%s" does not match bound agentRunId "%s".', $event->seq, $event->runId, $this->agentRunId));
        }

        return $event;
    }

    /**
     * Resolve the events.jsonl path for this child artifact.
     */
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
