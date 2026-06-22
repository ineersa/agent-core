<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\Lock\LockFactory;

/**
 * File-backed parent-scoped agent artifact registry.
 *
 * Stores a registry.json and per-child metadata.json under:
 *
 *   .hatfield/sessions/<parentRunId>/artifacts/agents/
 *     registry.json           — canonical entry list
 *     <artifactId>/
 *       metadata.json         — per-child identity/status/timestamps
 *       handoff.md            — human-readable handoff (empty on create)
 *       events.jsonl          — canonical RunEvent stream (AgentChildRunEventStore)
 *       state.json            — hot RunState cache (AgentChildRunStore)
 *
 * All registry mutations are protected by a per-parent-session lock
 * (hatfield-agent-artifacts-<parentRunId>).  Per-file atomic writes use
 * temp-file + rename where possible; at minimum the Symfony lock guards
 * the write.
 *
 * Path validation rejects empty IDs, "/", and ".." in path components
 * to prevent directory traversal.
 *
 * No DB row is created for child runs — the registry is entirely
 * file-backed and disposable with the parent session directory.
 */
final class AgentArtifactRegistry
{
    private const SCHEMA_VERSION = 1;

    /** Relative to parent session directory. */
    private const AGENTS_SUBDIR = 'artifacts/agents';

    private readonly string $sessionsBasePath;

    public function __construct(
        private readonly HatfieldSessionStore $hatfieldSessionStore,
        private readonly LockFactory $lockFactory,
    ) {
        $this->sessionsBasePath = $hatfieldSessionStore->resolveSessionsBasePath();
    }

    /**
     * Create a new agent artifact entry, its directory, metadata file,
     * and empty handoff file.
     *
     * Adds the entry to the registry.  The artifact starts in Pending
     * status; caller should transition to Running when the child run
     * actually begins.
     *
     * @param string $parentRunId parent session run ID
     * @param string $artifactId  Unique artifact identifier within the
     *                            parent session.  Must be a simple
     *                            filename-safe string.
     * @param string $agentRunId  agentCore run ID assigned to the child run
     * @param string $agentName   Agent definition name (e.g. "scout").
     *
     * @return AgentArtifactEntryDTO the newly created entry
     *
     * @throws \InvalidArgumentException when any ID contains path separators
     *                                   or traversal components
     * @throws \RuntimeException         when the artifact ID already exists
     */
    public function create(
        string $parentRunId,
        string $artifactId,
        string $agentRunId,
        string $agentName,
    ): AgentArtifactEntryDTO {
        $this->validatePathComponent($parentRunId, 'parentRunId');
        $this->validatePathComponent($artifactId, 'artifactId');
        $this->validatePathComponent($agentRunId, 'agentRunId');

        $lock = $this->lockFactory->createLock("hatfield-agent-artifacts-{$parentRunId}");
        $lock->acquire(true);

        try {
            $entries = $this->loadRegistry($parentRunId);

            // Reject duplicate artifact IDs within the same parent scope.
            foreach ($entries as $existing) {
                if ($existing->artifactId === $artifactId) {
                    throw new \RuntimeException(\sprintf('Agent artifact "%s" already exists for parent run "%s".', $artifactId, $parentRunId));
                }
            }

            $now = new \DateTimeImmutable();
            $paths = AgentArtifactPathsDTO::forArtifactId($artifactId);

            $entry = new AgentArtifactEntryDTO(
                artifactId: $artifactId,
                parentRunId: $parentRunId,
                agentRunId: $agentRunId,
                agentName: $agentName,
                status: AgentArtifactStatusEnum::Pending,
                paths: $paths,
                createdAt: $now,
            );

            // Create the artifact directory and files.
            $this->ensureArtifactDir($parentRunId, $artifactId);
            $this->writeMetadata($parentRunId, $entry);
            $this->writeHandoff($parentRunId, $artifactId, '');

            // Write the registry.
            $entries[] = $entry;
            $this->writeRegistry($parentRunId, $entries);

            return $entry;
        } finally {
            $lock->release();
        }
    }

    /**
     * Update an existing artifact entry.
     *
     * Only the status, timestamps, summary, and error/clarification
     * fields are mutable — identity fields (artifactId, parentRunId,
     * agentRunId, agentName, paths, createdAt) are preserved from the
     * existing entry.
     *
     * The registry and metadata file are kept in sync.  If the artifact
     * ID is not found, this is a no-op (returns null).
     *
     * @return AgentArtifactEntryDTO|null the updated entry, or null when
     *                                    the artifact is not found
     */
    public function update(
        string $parentRunId,
        string $artifactId,
        ?AgentArtifactStatusEnum $status = null,
        ?\DateTimeImmutable $startedAt = null,    // sentinel: null = no change
        ?\DateTimeImmutable $completedAt = null,  // sentinel: null = no change
        ?string $summary = null,                   // sentinel: null = no change
        ?string $failureReason = null,             // sentinel: null = no change
        ?string $needsClarification = null,        // sentinel: null = no change
    ): ?AgentArtifactEntryDTO {
        $lock = $this->lockFactory->createLock("hatfield-agent-artifacts-{$parentRunId}");
        $lock->acquire(true);

        try {
            $entries = $this->loadRegistry($parentRunId);
            $updated = null;

            foreach ($entries as $i => $entry) {
                if ($entry->artifactId !== $artifactId) {
                    continue;
                }

                $updated = new AgentArtifactEntryDTO(
                    artifactId: $entry->artifactId,
                    parentRunId: $entry->parentRunId,
                    agentRunId: $entry->agentRunId,
                    agentName: $entry->agentName,
                    status: $status ?? $entry->status,
                    paths: $entry->paths,
                    createdAt: $entry->createdAt,
                    startedAt: $startedAt ?? $entry->startedAt,
                    completedAt: $completedAt ?? $entry->completedAt,
                    summary: $summary ?? $entry->summary,
                    failureReason: $failureReason ?? $entry->failureReason,
                    needsClarification: $needsClarification ?? $entry->needsClarification,
                );

                $entries[$i] = $updated;

                break;
            }

            if (null === $updated) {
                return null;
            }

            $this->writeMetadata($parentRunId, $updated);
            $this->writeRegistry($parentRunId, $entries);

            return $updated;
        } finally {
            $lock->release();
        }
    }

    /**
     * Look up a single artifact entry by artifact ID within a parent scope.
     */
    public function get(string $parentRunId, string $artifactId): ?AgentArtifactEntryDTO
    {
        foreach ($this->list($parentRunId) as $entry) {
            if ($entry->artifactId === $artifactId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Look up a single artifact entry by agent run ID within a parent scope.
     *
     * The agentRunId is the AgentCore child run ID embedded in
     * child events and state.  Returns null when no matching entry exists.
     */
    public function findByAgentRunId(string $parentRunId, string $agentRunId): ?AgentArtifactEntryDTO
    {
        foreach ($this->list($parentRunId) as $entry) {
            if ($entry->agentRunId === $agentRunId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * List all artifact entries for a parent session.
     *
     * @return list<AgentArtifactEntryDTO>
     */
    public function list(string $parentRunId): array
    {
        $lock = $this->lockFactory->createLock("hatfield-agent-artifacts-{$parentRunId}");
        $lock->acquire(true);

        try {
            return $this->loadRegistry($parentRunId);
        } finally {
            $lock->release();
        }
    }

    /**
     * Resolve the absolute base path for a parent session's agent artifacts.
     */
    public function resolveArtifactsBasePath(string $parentRunId): string
    {
        return $this->sessionsBasePath.'/'.$parentRunId.'/'.self::AGENTS_SUBDIR;
    }

    /**
     * Resolve an absolute path to a child artifact directory.
     */
    public function resolveArtifactDir(string $parentRunId, string $artifactId): string
    {
        return $this->resolveArtifactsBasePath($parentRunId).'/'.$artifactId;
    }

    // ── Internal read/write methods ─────────────────────────────────────

    /**
     * Load all entries from registry.json for a parent session.
     *
     * @return list<AgentArtifactEntryDTO>
     */
    private function loadRegistry(string $parentRunId): array
    {
        $path = $this->registryPath($parentRunId);

        if (!is_readable($path)) {
            return [];
        }

        $json = file_get_contents($path);
        if (false === $json || '' === trim($json)) {
            return [];
        }

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!\is_array($data) || !isset($data['entries']) || !\is_array($data['entries'])) {
            return [];
        }

        $entries = [];
        foreach ($data['entries'] as $entryData) {
            if (!\is_array($entryData)) {
                continue;
            }

            $entry = $this->hydrateEntry($entryData);
            if (null !== $entry) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * Write entries to registry.json for a parent session.
     *
     * Uses a temp file + rename to avoid partial writes from
     * crashes mid-write operating on the real file.
     *
     * @param list<AgentArtifactEntryDTO> $entries
     */
    private function writeRegistry(string $parentRunId, array $entries): void
    {
        $path = $this->registryPath($parentRunId);
        $dir = \dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $serialized = [];
        foreach ($entries as $entry) {
            $serialized[] = $this->serializeEntry($entry);
        }

        $json = json_encode(
            ['schema_version' => self::SCHEMA_VERSION, 'entries' => $serialized],
            \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR,
        );

        // Temp-file + rename for atomic replacement.
        $tmpPath = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmpPath, $json, \LOCK_EX);
        chmod($tmpPath, 0644);
        rename($tmpPath, $path);
    }

    /**
     * Write per-child metadata.json.
     */
    private function writeMetadata(string $parentRunId, AgentArtifactEntryDTO $entry): void
    {
        $path = $this->absolutePath($parentRunId, $entry->paths->metadataPath);
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $json = json_encode($this->serializeEntryMetadata($entry), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        $tmpPath = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        file_put_contents($tmpPath, $json, \LOCK_EX);
        chmod($tmpPath, 0644);
        rename($tmpPath, $path);
    }

    /**
     * Write (or overwrite) the handoff.md file for a child artifact.
     *
     * An empty string creates an empty file placeholder so the path
     * reference is always valid.
     */
    private function writeHandoff(string $parentRunId, string $artifactId, string $content): void
    {
        $paths = AgentArtifactPathsDTO::forArtifactId($artifactId);
        $path = $this->absolutePath($parentRunId, $paths->handoffPath);
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($path, $content, \LOCK_EX);
        chmod($path, 0644);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Ensure the artifact directory exists for a given parent + artifact ID.
     */
    private function ensureArtifactDir(string $parentRunId, string $artifactId): void
    {
        $path = $this->resolveArtifactDir($parentRunId, $artifactId);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    private function registryPath(string $parentRunId): string
    {
        return $this->sessionsBasePath.'/'.$parentRunId.'/'.self::AGENTS_SUBDIR.'/registry.json';
    }

    /**
     * Resolve an absolute path from a parent-relative artifact path.
     */
    private function absolutePath(string $parentRunId, string $relative): string
    {
        return $this->sessionsBasePath.'/'.$parentRunId.'/'.$relative;
    }

    /**
     * Hydrate an AgentArtifactEntryDTO from a registry entry array.
     *
     * @param array<string, mixed> $data
     */
    private function hydrateEntry(array $data): ?AgentArtifactEntryDTO
    {
        $artifactId = $data['artifact_id'] ?? null;
        $parentRunId = $data['parent_run_id'] ?? null;
        $agentRunId = $data['agent_run_id'] ?? null;
        $agentName = $data['agent_name'] ?? null;
        $status = $data['status'] ?? null;
        $createdAt = $data['created_at'] ?? null;

        if (!\is_string($artifactId)
            || !\is_string($parentRunId)
            || !\is_string($agentRunId)
            || !\is_string($agentName)
            || !\is_string($status)
            || !\is_string($createdAt)
        ) {
            return null;
        }

        $statusEnum = AgentArtifactStatusEnum::tryFrom($status);
        if (null === $statusEnum) {
            return null;
        }

        try {
            $createdAtDt = new \DateTimeImmutable($createdAt);
        } catch (\Throwable) {
            return null;
        }

        $startedAt = null;
        if (\is_string($data['started_at'] ?? null)) {
            try {
                $startedAt = new \DateTimeImmutable($data['started_at']);
            } catch (\Throwable) {
                // tolerate unparseable timestamp
            }
        }

        $completedAt = null;
        if (\is_string($data['completed_at'] ?? null)) {
            try {
                $completedAt = new \DateTimeImmutable($data['completed_at']);
            } catch (\Throwable) {
                // tolerate unparseable timestamp
            }
        }

        return new AgentArtifactEntryDTO(
            artifactId: $artifactId,
            parentRunId: $parentRunId,
            agentRunId: $agentRunId,
            agentName: $agentName,
            status: $statusEnum,
            paths: AgentArtifactPathsDTO::forArtifactId($artifactId),
            createdAt: $createdAtDt,
            startedAt: $startedAt,
            completedAt: $completedAt,
            summary: \is_string($data['summary'] ?? null) ? $data['summary'] : null,
            failureReason: \is_string($data['failure_reason'] ?? null) ? $data['failure_reason'] : null,
            needsClarification: \is_string($data['needs_clarification'] ?? null) ? $data['needs_clarification'] : null,
        );
    }

    /**
     * Serialize an entry for registry.json storage.
     *
     * @return array<string, mixed>
     */
    private function serializeEntry(AgentArtifactEntryDTO $entry): array
    {
        $data = [
            'artifact_id' => $entry->artifactId,
            'parent_run_id' => $entry->parentRunId,
            'agent_run_id' => $entry->agentRunId,
            'agent_name' => $entry->agentName,
            'status' => $entry->status->value,
            'created_at' => $entry->createdAt->format(\DateTimeInterface::ATOM),
            'handoff_path' => $entry->paths->handoffPath,
            'metadata_path' => $entry->paths->metadataPath,
            'events_path' => $entry->paths->eventsPath,
            'state_path' => $entry->paths->statePath,
        ];

        if (null !== $entry->startedAt) {
            $data['started_at'] = $entry->startedAt->format(\DateTimeInterface::ATOM);
        }
        if (null !== $entry->completedAt) {
            $data['completed_at'] = $entry->completedAt->format(\DateTimeInterface::ATOM);
        }
        if (null !== $entry->summary) {
            $data['summary'] = $entry->summary;
        }
        if (null !== $entry->failureReason) {
            $data['failure_reason'] = $entry->failureReason;
        }
        if (null !== $entry->needsClarification) {
            $data['needs_clarification'] = $entry->needsClarification;
        }

        return $data;
    }

    /**
     * Serialize an entry for metadata.json storage.
     *
     * @return array<string, mixed>
     */
    private function serializeEntryMetadata(AgentArtifactEntryDTO $entry): array
    {
        return [
            'kind' => 'agent_child',
            'artifact_id' => $entry->artifactId,
            'parent_run_id' => $entry->parentRunId,
            'agent_run_id' => $entry->agentRunId,
            'agent_name' => $entry->agentName,
            'status' => $entry->status->value,
            'created_at' => $entry->createdAt->format(\DateTimeInterface::ATOM),
            'started_at' => $entry->startedAt?->format(\DateTimeInterface::ATOM),
            'completed_at' => $entry->completedAt?->format(\DateTimeInterface::ATOM),
            'summary' => $entry->summary,
            'failure_reason' => $entry->failureReason,
            'needs_clarification' => $entry->needsClarification,
            'events_path' => $entry->paths->eventsPath,
            'state_path' => $entry->paths->statePath,
            'handoff_path' => $entry->paths->handoffPath,
        ];
    }

    /**
     * Reject path components that could escape the session directory.
     *
     * @throws \InvalidArgumentException
     */
    private function validatePathComponent(string $value, string $field): void
    {
        if ('' === $value) {
            throw new \InvalidArgumentException(\sprintf('"%s" must not be empty.', $field));
        }

        if (false !== strpbrk($value, '/\\')) {
            throw new \InvalidArgumentException(\sprintf('"%s" must not contain path separators: got "%s".', $field, $value));
        }

        if ('..' === $value) {
            throw new \InvalidArgumentException(\sprintf('"%s" must not be "..".', $field));
        }
    }
}
