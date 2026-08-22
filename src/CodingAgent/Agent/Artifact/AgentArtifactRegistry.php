<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Utility\AtomicFileWriter;
use Ineersa\CodingAgent\Utility\AtomicFileWriterException;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Uid\UuidV7;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * File-backed parent-scoped agent artifact registry.
 *
 * Stores a registry.json and per-child metadata.json under:
 *
 *   .hatfield/sessions/<parentRunId>/artifacts/agents/
 *     registry.json           — canonical entry list
 *     <artifactId>/
 *       metadata.json         — per-child identity/status/timestamps
 *       events.jsonl          — canonical RunEvent stream (AgentChildRunEventStore)
 *       state.json            — hot RunState cache (AgentChildRunStore)
 *       handoffs/
 *         index.json          — immutable handoff entries (id, created_at, status, summary, path)
 *         <uuid>.md           — one handoff body per finalize (append-only)
 *
 * All registry mutations are protected by a per-parent-session lock
 * (hatfield-agent-artifacts-<parentRunId>).  Per-file atomic writes use
 * temp-file + rename where possible; at minimum the Symfony lock guards
 * the write.
 *
 * Path resolution and validation are delegated to
 * {@see SessionAgentArtifactPathResolver}.  Serialization/deserialization
 * of entries and registry uses Symfony Serializer.
 *
 * No DB row is created for child runs — the registry is entirely
 * file-backed and disposable with the parent session directory.
 */
final class AgentArtifactRegistry
{
    private const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly SessionAgentArtifactPathResolver $pathResolver,
        private readonly NormalizerInterface&DenormalizerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * Create a new agent artifact entry, its directory, metadata file,
     * and empty handoffs/ index.
     *
     * Adds the entry to the registry.  The artifact starts in Pending
     * status; caller should transition to Running when the child run
     * actually begins.
     *
     * @param string                $parentRunId parent session run ID
     * @param string                $artifactId  Unique artifact identifier within the
     *                                           parent session.  Must be a simple
     *                                           filename-safe string.
     * @param string                $agentRunId  agentCore run ID assigned to the child run
     * @param string                $agentName   Agent definition name (e.g. "scout").
     * @param AgentArtifactKindEnum $kind        Kind discriminator (e.g. Subagent, Fork)
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
        AgentArtifactKindEnum $kind,
    ): AgentArtifactEntryDTO {
        $this->pathResolver->validatePathComponent($parentRunId, 'parentRunId');
        $this->pathResolver->validatePathComponent($artifactId, 'artifactId');
        $this->pathResolver->validatePathComponent($agentRunId, 'agentRunId');

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

            $entry = $this->buildPendingEntry($parentRunId, $artifactId, $agentRunId, $agentName, $kind);
            $entries[] = $entry;
            $this->persistPendingEntry($parentRunId, $artifactId, $entry, $entries);

            return $entry;
        } finally {
            $lock->release();
        }
    }

    /**
     * Idempotently reserve an artifact for an exact immutable child identity.
     *
     * When the artifact already exists with the same agentRunId, agentName, and kind,
     * returns the existing entry without mutating status (Pending, Running, or terminal).
     * Conflicting identity fields throw so retries cannot fork a second child.
     */
    public function ensureReserved(
        string $parentRunId,
        string $artifactId,
        string $agentRunId,
        string $agentName,
        AgentArtifactKindEnum $kind,
    ): AgentArtifactEntryDTO {
        $this->pathResolver->validatePathComponent($parentRunId, 'parentRunId');
        $this->pathResolver->validatePathComponent($artifactId, 'artifactId');
        $this->pathResolver->validatePathComponent($agentRunId, 'agentRunId');

        $lock = $this->lockFactory->createLock("hatfield-agent-artifacts-{$parentRunId}");
        $lock->acquire(true);

        try {
            $entries = $this->loadRegistry($parentRunId);

            foreach ($entries as $existing) {
                if ($existing->artifactId !== $artifactId) {
                    continue;
                }

                if ($existing->agentRunId !== $agentRunId
                    || $existing->agentName !== $agentName
                    || $existing->kind !== $kind) {
                    throw new \RuntimeException(\sprintf('Agent artifact "%s" already exists for parent run "%s" with conflicting identity (agentRunId/agentName/kind).', $artifactId, $parentRunId));
                }

                return $existing;
            }

            $entry = $this->buildPendingEntry($parentRunId, $artifactId, $agentRunId, $agentName, $kind);
            $entries[] = $entry;
            $this->persistPendingEntry($parentRunId, $artifactId, $entry, $entries);

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
     * Sentinels: nullable parameters use null as "leave existing field
     * unchanged".  This means a lifecycle field (e.g., summary) cannot
     * be cleared back to null after it has been set.  This matches the
     * write-once-forward lifecycle model.
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
        $this->pathResolver->validatePathComponent($parentRunId, 'parentRunId');
        $this->pathResolver->validatePathComponent($artifactId, 'artifactId');

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
                    kind: $entry->kind,
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

            $this->writeRegistry($parentRunId, $entries);
            $this->writeMetadata($parentRunId, $updated);

            return $updated;
        } finally {
            $lock->release();
        }
    }

    /**
     * Atomically promote Pending/NeedsClarification to Running under the parent artifact lock.
     *
     * Running and terminal statuses are left unchanged so a concurrent terminal write cannot be
     * regressed by a later forward-only promotion.
     */
    public function promoteToRunningForwardOnly(
        string $parentRunId,
        string $artifactId,
        \DateTimeImmutable $startedAt,
    ): ?AgentArtifactEntryDTO {
        $this->pathResolver->validatePathComponent($parentRunId, 'parentRunId');
        $this->pathResolver->validatePathComponent($artifactId, 'artifactId');

        $lock = $this->lockFactory->createLock("hatfield-agent-artifacts-{$parentRunId}");
        $lock->acquire(true);

        try {
            $entries = $this->loadRegistry($parentRunId);
            $updated = null;

            foreach ($entries as $i => $entry) {
                if ($entry->artifactId !== $artifactId) {
                    continue;
                }

                if (\in_array($entry->status, [
                    AgentArtifactStatusEnum::Completed,
                    AgentArtifactStatusEnum::Failed,
                    AgentArtifactStatusEnum::Cancelled,
                ], true)) {
                    return $entry;
                }

                if (AgentArtifactStatusEnum::Running === $entry->status) {
                    return $entry;
                }

                if (!\in_array($entry->status, [
                    AgentArtifactStatusEnum::Pending,
                    AgentArtifactStatusEnum::NeedsClarification,
                ], true)) {
                    return $entry;
                }

                $updated = new AgentArtifactEntryDTO(
                    artifactId: $entry->artifactId,
                    parentRunId: $entry->parentRunId,
                    agentRunId: $entry->agentRunId,
                    agentName: $entry->agentName,
                    kind: $entry->kind,
                    status: AgentArtifactStatusEnum::Running,
                    paths: $entry->paths,
                    createdAt: $entry->createdAt,
                    startedAt: $startedAt,
                    completedAt: $entry->completedAt,
                    summary: $entry->summary,
                    failureReason: $entry->failureReason,
                    needsClarification: $entry->needsClarification,
                );

                $entries[$i] = $updated;

                break;
            }

            if (null === $updated) {
                return null;
            }

            $this->writeRegistry($parentRunId, $entries);
            $this->writeMetadata($parentRunId, $updated);

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
        $this->pathResolver->validatePathComponent($parentRunId, 'parentRunId');
        $this->pathResolver->validatePathComponent($artifactId, 'artifactId');

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
        $this->pathResolver->validatePathComponent($parentRunId, 'parentRunId');
        $this->pathResolver->validatePathComponent($agentRunId, 'agentRunId');

        foreach ($this->list($parentRunId) as $entry) {
            if ($entry->agentRunId === $agentRunId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Discard a Pending-only reservation: canonical registry row plus artifact directory sidecars.
     *
     * Running or terminal artifacts are left unchanged (returns null).
     *
     * Ordering: registry.json is written before directory removal so list/get/load never resurrect a
     * discarded Pending child from a stale row. Sidecar deletion is best-effort afterward; a failure
     * leaves orphan files under the parent session but does not roll back the registry — callers retry
     * discard or manual cleanup, and in-process cache unregister is handled by the lifecycle adapter.
     */
    public function discardPendingReservation(string $parentRunId, string $artifactId): ?string
    {
        $this->pathResolver->validatePathComponent($parentRunId, 'parentRunId');
        $this->pathResolver->validatePathComponent($artifactId, 'artifactId');

        $lock = $this->lockFactory->createLock("hatfield-agent-artifacts-{$parentRunId}");
        $lock->acquire(true);

        try {
            $entries = $this->loadRegistry($parentRunId);
            $filtered = [];
            $agentRunId = null;
            foreach ($entries as $entry) {
                if ($entry->artifactId === $artifactId && AgentArtifactStatusEnum::Pending === $entry->status) {
                    $agentRunId = $entry->agentRunId;

                    continue;
                }
                $filtered[] = $entry;
            }
            if (null === $agentRunId) {
                return null;
            }

            // Canonical registry first — readers must not see a Pending row after a successful discard.
            $this->writeRegistry($parentRunId, $filtered);

            // Sidecars (metadata/events/state/handoffs) are disposable once the row is gone.
            $this->removeReservedArtifactDirectory($parentRunId, $artifactId);

            return $agentRunId;
        } finally {
            $lock->release();
        }
    }

    /**
     * @return list<AgentArtifactEntryDTO>
     */
    public function list(string $parentRunId): array
    {
        $this->pathResolver->validatePathComponent($parentRunId, 'parentRunId');

        $lock = $this->lockFactory->createLock("hatfield-agent-artifacts-{$parentRunId}");
        $lock->acquire(true);

        try {
            return $this->loadRegistry($parentRunId);
        } finally {
            $lock->release();
        }
    }

    // ── Public file writers ─────────────────────────────────────────────

    /**
     * Read the latest immutable handoff body for an artifact.
     *
     * Latest is the index entry with the greatest created_at, with id as a
     * stable tie-break. Returns an empty string when no handoffs exist yet.
     *
     * @throws \InvalidArgumentException when IDs contain path separators
     * @throws \RuntimeException         when the latest handoff file is unreadable
     */
    public function readHandoff(string $parentRunId, string $artifactId): string
    {
        $this->pathResolver->validatePathComponent($parentRunId, 'parentRunId');
        $this->pathResolver->validatePathComponent($artifactId, 'artifactId');

        $latest = $this->latestHandoffEntry($parentRunId, $artifactId);
        if (null === $latest) {
            return '';
        }

        return $this->readHandoffFile($parentRunId, $artifactId, $latest['id']);
    }

    /**
     * Append an immutable handoff for an existing artifact.
     *
     * Writes handoffs/<uuid>.md and appends an index entry using the status
     * and summary known at finalize time. Prior handoffs are never rewritten.
     *
     * @throws \InvalidArgumentException when IDs contain path separators
     */
    public function writeHandoff(
        string $parentRunId,
        string $artifactId,
        string $content,
        ?AgentArtifactStatusEnum $status = null,
        ?string $summary = null,
    ): string {
        $this->pathResolver->validatePathComponent($parentRunId, 'parentRunId');
        $this->pathResolver->validatePathComponent($artifactId, 'artifactId');

        $lock = $this->lockFactory->createLock("hatfield-agent-artifacts-{$parentRunId}");
        $lock->acquire(true);

        try {
            return $this->appendImmutableHandoff(
                $parentRunId,
                $artifactId,
                $content,
                $status,
                $summary,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * List immutable handoffs for an artifact (oldest → newest by created_at, id tie-break).
     *
     * @return list<array{id: string, created_at: string, status: ?string, summary: ?string, path: string}>
     */
    public function listHandoffHistory(string $parentRunId, string $artifactId): array
    {
        $this->pathResolver->validatePathComponent($parentRunId, 'parentRunId');
        $this->pathResolver->validatePathComponent($artifactId, 'artifactId');

        return $this->normalizedHandoffEntries($parentRunId, $artifactId);
    }

    /**
     * Read one immutable handoff body by handoff id (uuid).
     *
     * @throws \InvalidArgumentException when the handoff does not exist
     */
    public function readHandoffHistoryEntry(string $parentRunId, string $artifactId, string $handoffId): string
    {
        $this->pathResolver->validatePathComponent($parentRunId, 'parentRunId');
        $this->pathResolver->validatePathComponent($artifactId, 'artifactId');

        $handoffId = trim($handoffId);
        if ('' === $handoffId) {
            throw new \InvalidArgumentException('Handoff id must not be blank.');
        }
        $this->pathResolver->validatePathComponent($handoffId, 'handoffId');

        foreach ($this->normalizedHandoffEntries($parentRunId, $artifactId) as $entry) {
            if ($entry['id'] === $handoffId) {
                return $this->readHandoffFile($parentRunId, $artifactId, $handoffId);
            }
        }

        throw new \InvalidArgumentException(\sprintf('No handoff "%s" for artifact "%s".', $handoffId, $artifactId));
    }

    // ── Internal read/write methods ─────────────────────────────────────

    private function buildPendingEntry(
        string $parentRunId,
        string $artifactId,
        string $agentRunId,
        string $agentName,
        AgentArtifactKindEnum $kind,
    ): AgentArtifactEntryDTO {
        $now = new \DateTimeImmutable();
        $paths = AgentArtifactPathsDTO::forArtifactId($artifactId);

        return new AgentArtifactEntryDTO(
            artifactId: $artifactId,
            parentRunId: $parentRunId,
            agentRunId: $agentRunId,
            agentName: $agentName,
            kind: $kind,
            status: AgentArtifactStatusEnum::Pending,
            paths: $paths,
            createdAt: $now,
        );
    }

    /**
     * @param list<AgentArtifactEntryDTO> $entries
     */
    private function persistPendingEntry(
        string $parentRunId,
        string $artifactId,
        AgentArtifactEntryDTO $entry,
        array $entries,
    ): void {
        $this->ensureArtifactDir($parentRunId, $artifactId);
        $this->ensureEmptyHandoffsIndex($parentRunId, $artifactId);

        // Write the canonical registry first — if a later sidecar write
        // fails, the canonical registry is still correct.  metadata.json
        // is never read by this code; it is an inspectable sidecar.
        $this->writeRegistry($parentRunId, $entries);
        $this->writeMetadata($parentRunId, $entry);
    }

    /**
     * @return list<AgentArtifactEntryDTO>
     */
    private function loadRegistry(string $parentRunId): array
    {
        $path = $this->pathResolver->registryPath($parentRunId);

        // Missing file is legitimate empty (no artifacts yet).
        // An existing but unreadable registry is a data integrity failure.
        if (!is_file($path)) {
            return [];
        }

        if (!is_readable($path)) {
            throw new \RuntimeException(\sprintf('Registry.json for parent run "%s" exists but is not readable.', $parentRunId));
        }

        $json = file_get_contents($path);
        if (false === $json || '' === trim($json)) {
            return [];
        }

        try {
            $data = json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \RuntimeException(\sprintf('Corrupt registry.json for parent run "%s" — not parseable as JSON: %s', $parentRunId, $e->getMessage()), previous: $e);
        }

        if (!\is_array($data) || !isset($data['entries']) || !\is_array($data['entries'])) {
            throw new \RuntimeException(\sprintf('Registry.json for parent run "%s" missing required "entries" key.', $parentRunId));
        }

        $schemaVersion = $data['schema_version'] ?? null;
        if (self::SCHEMA_VERSION !== $schemaVersion) {
            throw new \RuntimeException(\sprintf('Registry.json for parent run "%s" has unsupported schema_version "%s" (expected %d).', $parentRunId, var_export($schemaVersion, true), self::SCHEMA_VERSION));
        }

        $entries = [];
        foreach ($data['entries'] as $entryData) {
            if (!\is_array($entryData)) {
                throw new \RuntimeException(\sprintf('Registry.json for parent run "%s" contains a non-associative entry.', $parentRunId));
            }

            $entries[] = $this->hydrateEntry($entryData, $parentRunId);
        }

        return $entries;
    }

    /**
     * Write entries to registry.json for a parent session.
     *
     * Uses Serializer for entry normalization and temp-file+rename for
     * atomic replacement.
     *
     * @param list<AgentArtifactEntryDTO> $entries
     */
    private function writeRegistry(string $parentRunId, array $entries): void
    {
        $path = $this->pathResolver->registryPath($parentRunId);

        $json = json_encode(
            [
                'schema_version' => self::SCHEMA_VERSION,
                'entries' => array_map(
                    fn (AgentArtifactEntryDTO $entry): array => $this->normalizeEntry($entry),
                    $entries,
                ),
            ],
            \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR,
        );

        try {
            AtomicFileWriter::write($path, $json, fileMode: SessionAgentArtifactPathResolver::FILE_PERMISSIONS);
        } catch (AtomicFileWriterException $exception) {
            throw new \RuntimeException(\sprintf('Failed to write registry.json for parent run "%s".', $parentRunId), previous: $exception);
        }
    }

    /**
     * Write per-child metadata.json.
     *
     * metadata.json is an inspectable sidecar for external tooling.
     * registry.json remains the canonical load source —
     * metadata.json is written but never read by this code.
     */
    private function writeMetadata(string $parentRunId, AgentArtifactEntryDTO $entry): void
    {
        $path = $this->pathResolver->absolutePath($parentRunId, $entry->paths->metadataPath);

        $json = json_encode($this->normalizeEntry($entry), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);

        try {
            AtomicFileWriter::write($path, $json, fileMode: SessionAgentArtifactPathResolver::FILE_PERMISSIONS);
        } catch (AtomicFileWriterException $exception) {
            throw new \RuntimeException(\sprintf('Failed to write metadata.json for artifact "%s" parent "%s".', $entry->artifactId, $parentRunId), previous: $exception);
        }
    }

    /**
     * Append one immutable handoff file + index entry. Caller must hold the parent lock.
     */
    private function appendImmutableHandoff(
        string $parentRunId,
        string $artifactId,
        string $content,
        ?AgentArtifactStatusEnum $status,
        ?string $summary,
    ): string {
        $this->ensureArtifactDir($parentRunId, $artifactId);

        $handoffId = UuidV7::v7()->toRfc4122();
        $paths = AgentArtifactPathsDTO::forArtifactId($artifactId);
        $relative = "{$paths->artifactDir}/handoffs/{$handoffId}.md";
        $absolute = $this->pathResolver->absolutePath($parentRunId, $relative);

        try {
            AtomicFileWriter::write($absolute, $content, fileMode: SessionAgentArtifactPathResolver::FILE_PERMISSIONS);
        } catch (AtomicFileWriterException $exception) {
            throw new \RuntimeException(\sprintf('Failed to write handoff "%s" for artifact "%s" parent "%s".', $handoffId, $artifactId, $parentRunId), previous: $exception);
        }

        if (null !== $summary && '' !== trim($summary)) {
            $summary = mb_substr(preg_replace('/\s+/', ' ', $summary) ?? $summary, 0, 240);
        } else {
            $summary = null;
        }

        $entries = $this->normalizedHandoffEntries($parentRunId, $artifactId);
        $entries[] = [
            'id' => $handoffId,
            'created_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'status' => $status?->value,
            'summary' => $summary,
            'path' => $relative,
        ];

        $this->writeHandoffIndex($parentRunId, $artifactId, [
            'schema_version' => 1,
            'entries' => $entries,
        ]);

        return $handoffId;
    }

    /**
     * Ensure handoffs/ exists with an empty index.json (create-time placeholder).
     * Caller must already hold the parent lock when used from persistPendingEntry.
     */
    private function ensureEmptyHandoffsIndex(string $parentRunId, string $artifactId): void
    {
        $index = $this->readHandoffIndex($parentRunId, $artifactId);
        if ([] !== ($index['entries'] ?? [])) {
            return;
        }

        $this->writeHandoffIndex($parentRunId, $artifactId, [
            'schema_version' => 1,
            'entries' => [],
        ]);
    }

    /**
     * @return list<array{id: string, created_at: string, status: ?string, summary: ?string, path: string}>
     */
    private function normalizedHandoffEntries(string $parentRunId, string $artifactId): array
    {
        $index = $this->readHandoffIndex($parentRunId, $artifactId);
        $entries = $index['entries'] ?? [];
        if (!\is_array($entries)) {
            return [];
        }

        $out = [];
        foreach ($entries as $entry) {
            if (!\is_array($entry) || !isset($entry['id']) || !\is_string($entry['id']) || '' === trim($entry['id'])) {
                continue;
            }
            $id = trim($entry['id']);
            $out[] = [
                'id' => $id,
                'created_at' => \is_string($entry['created_at'] ?? null) ? $entry['created_at'] : '',
                'status' => \is_string($entry['status'] ?? null) ? $entry['status'] : null,
                'summary' => \is_string($entry['summary'] ?? null) ? $entry['summary'] : null,
                'path' => \is_string($entry['path'] ?? null) && '' !== $entry['path']
                    ? $entry['path']
                    : "artifacts/agents/{$artifactId}/handoffs/{$id}.md",
            ];
        }

        usort(
            $out,
            static function (array $a, array $b): int {
                $cmp = strcmp($a['created_at'], $b['created_at']);
                if (0 !== $cmp) {
                    return $cmp;
                }

                return strcmp($a['id'], $b['id']);
            },
        );

        return $out;
    }

    /**
     * @return array{id: string, created_at: string, status: ?string, summary: ?string, path: string}|null
     */
    private function latestHandoffEntry(string $parentRunId, string $artifactId): ?array
    {
        $entries = $this->normalizedHandoffEntries($parentRunId, $artifactId);
        if ([] === $entries) {
            return null;
        }

        return $entries[array_key_last($entries)];
    }

    private function readHandoffFile(string $parentRunId, string $artifactId, string $handoffId): string
    {
        // Derive the FS path from validated components only. Index `path` is
        // informational — never open a stored relative path from index.json.
        $this->pathResolver->validatePathComponent($handoffId, 'handoffId');
        $paths = AgentArtifactPathsDTO::forArtifactId($artifactId);
        $relative = "{$paths->artifactDir}/handoffs/{$handoffId}.md";
        $path = $this->pathResolver->absolutePath($parentRunId, $relative);
        if (!is_file($path)) {
            throw new \InvalidArgumentException(\sprintf('No handoff "%s" for artifact "%s".', $handoffId, $artifactId));
        }
        if (!is_readable($path)) {
            throw new \RuntimeException(\sprintf('Handoff "%s" for artifact "%s" parent "%s" is not readable.', $handoffId, $artifactId, $parentRunId));
        }

        $content = file_get_contents($path);

        return false === $content ? '' : $content;
    }

    /**
     * @return array{schema_version?: int, entries?: list<mixed>}
     */
    private function readHandoffIndex(string $parentRunId, string $artifactId): array
    {
        $paths = AgentArtifactPathsDTO::forArtifactId($artifactId);
        $indexPath = $this->pathResolver->absolutePath($parentRunId, "{$paths->artifactDir}/handoffs/index.json");
        if (!is_file($indexPath) || !is_readable($indexPath)) {
            return ['schema_version' => 1, 'entries' => []];
        }

        $raw = file_get_contents($indexPath);
        if (false === $raw || '' === trim($raw)) {
            return ['schema_version' => 1, 'entries' => []];
        }

        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['schema_version' => 1, 'entries' => []];
        }

        return \is_array($decoded) ? $decoded : ['schema_version' => 1, 'entries' => []];
    }

    /**
     * @param array{schema_version: int, entries: list<array<string, mixed>>} $index
     */
    private function writeHandoffIndex(string $parentRunId, string $artifactId, array $index): void
    {
        $paths = AgentArtifactPathsDTO::forArtifactId($artifactId);
        $indexPath = $this->pathResolver->absolutePath($parentRunId, "{$paths->artifactDir}/handoffs/index.json");

        try {
            AtomicFileWriter::write(
                $indexPath,
                json_encode($index, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT)."\n",
                fileMode: SessionAgentArtifactPathResolver::FILE_PERMISSIONS,
            );
        } catch (AtomicFileWriterException|\JsonException $exception) {
            throw new \RuntimeException(\sprintf('Failed to write handoffs/index.json for artifact "%s" parent "%s".', $artifactId, $parentRunId), previous: $exception);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Remove a reserved artifact directory tree after a Pending-only discard.
     */
    private function removeReservedArtifactDirectory(string $parentRunId, string $artifactId): void
    {
        $dir = $this->pathResolver->resolveArtifactDir($parentRunId, $artifactId);
        if (!is_dir($dir)) {
            return;
        }

        $this->removeDirectoryTree($dir);
    }

    /**
     * @throws \RuntimeException when removal fails
     */
    private function removeDirectoryTree(string $dir): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $path = $item->getPathname();
            if ($item->isDir()) {
                if (!@rmdir($path)) {
                    throw new \RuntimeException(\sprintf('Failed to remove artifact directory "%s".', $path));
                }

                continue;
            }
            if (!@unlink($path)) {
                throw new \RuntimeException(\sprintf('Failed to remove artifact file "%s".', $path));
            }
        }

        if (!@rmdir($dir)) {
            throw new \RuntimeException(\sprintf('Failed to remove artifact root directory "%s".', $dir));
        }
    }

    /**
     * Ensure the artifact directory exists for a given parent + artifact ID.
     */
    private function ensureArtifactDir(string $parentRunId, string $artifactId): void
    {
        $path = $this->pathResolver->resolveArtifactDir($parentRunId, $artifactId);
        if (!is_dir($path)) {
            mkdir($path, SessionAgentArtifactPathResolver::DIR_PERMISSIONS, true);
        }
    }

    /**
     * Normalize an entry for JSON storage using Symfony Serializer.
     *
     * Produces a snake_case array suitable for registry.json or metadata.json.
     *
     * @return array<string, mixed>
     */
    private function normalizeEntry(AgentArtifactEntryDTO $entry): array
    {
        /* @var array<string, mixed> */
        return $this->serializer->normalize($entry);
    }

    /**
     * Denormalize an entry from registry array data using Symfony Serializer,
     * then validate with Symfony Validator.
     *
     * Throws on corrupt/malformed entries so corruption cannot be
     * silently clobbered on the next write.
     *
     * @param array<string, mixed> $data
     * @param string               $parentRunId for error context
     *
     * @throws \RuntimeException when required fields are missing, malformed,
     *                           or validation fails
     */
    private function hydrateEntry(array $data, string $parentRunId): AgentArtifactEntryDTO
    {
        try {
            /** @var AgentArtifactEntryDTO $entry */
            $entry = $this->serializer->denormalize($data, AgentArtifactEntryDTO::class);
        } catch (\Throwable $e) {
            // Serializer throws various exceptions for type mismatches,
            // missing constructors, unrecognized enum values, etc.
            $artifactId = \is_string($data['artifact_id'] ?? null) ? $data['artifact_id'] : 'unknown';
            throw new \RuntimeException(\sprintf('Registry entry for parent run "%s" artifact "%s" could not be denormalized: %s', $parentRunId, $artifactId, $e->getMessage()), previous: $e);
        }

        // Validate stored paths match the canonical paths for this artifact ID.
        $expectedPaths = AgentArtifactPathsDTO::forArtifactId($entry->artifactId);
        if ($entry->paths->artifactDir !== $expectedPaths->artifactDir
            || $entry->paths->metadataPath !== $expectedPaths->metadataPath
            || $entry->paths->eventsPath !== $expectedPaths->eventsPath
            || $entry->paths->statePath !== $expectedPaths->statePath
        ) {
            throw new \RuntimeException(\sprintf('Registry entry for parent run "%s" artifact "%s" has unexpected paths.', $parentRunId, $entry->artifactId));
        }

        // Run Symfony Validator on the denormalized entry to catch type/domain errors.
        $violations = $this->validator->validate($entry);
        if ($violations->count() > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $messages[] = \sprintf('%s: %s', $violation->getPropertyPath(), $violation->getMessage());
            }
            throw new \RuntimeException(\sprintf('Registry entry for parent run "%s" artifact "%s" failed validation: %s', $parentRunId, $entry->artifactId, implode('; ', $messages)));
        }

        return $entry;
    }
}
