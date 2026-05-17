<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Infrastructure\Storage;

use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
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
 */
final class SessionRunEventStore implements EventStoreInterface
{
    private string $sessionsBasePath;

    public function __construct(
        string $projectDir,
        private readonly EventPayloadNormalizer $eventPayloadNormalizer,
        private readonly LockFactory $lockFactory,
    ) {
        $this->sessionsBasePath = $projectDir.'/.hatfield/sessions';
    }

    /**
     * Set the sessions base directory.
     *
     * Called by the CodingAgent runtime layer before any run operations
     * to ensure the store writes to the active project cwd, not the app
     * install root. Required for PHAR distribution.
     */
    public function setSessionsBasePath(string $path): void
    {
        $this->sessionsBasePath = $path;
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
            } catch (\JsonException) {
                continue;
            }

            if (!\is_array($payload)) {
                continue;
            }

            $event = $this->eventPayloadNormalizer->denormalizeRunEvent($payload);
            if (null === $event) {
                continue;
            }

            // Validate embedded runId matches directory (canonical source)
            if ($event->runId !== $runId) {
                throw new \RuntimeException(\sprintf('RunEvent integrity error at seq %d: embedded runId "%s" does not match directory "%s".', $event->seq, $event->runId, $runId));
            }

            $events[] = $event;
        }

        usort($events, static fn (RunEvent $left, RunEvent $right): int => $left->seq <=> $right->seq);

        return $events;
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
