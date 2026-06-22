<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Artifact;

use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
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
 *       handoff.md            — human-readable handoff (empty on create)
 *       events.jsonl          — canonical RunEvent stream (AgentChildRunEventStore)
 *       state.json            — hot RunState cache (AgentChildRunStore)
 *
 * All registry mutations are protected by a per-parent-session lock
 * (hatfield-agent-artifacts-<parentRunId>).  Per-file atomic writes use
 * temp-file + rename where possible; at minimum the Symfony lock guards
 * the write.
 *
 * Path resolution and validation are delegated to
 * {@see AgentArtifactPathResolver}.  Serialization/deserialization
 * of entries and registry uses Symfony Serializer.
 *
 * No DB row is created for child runs — the registry is entirely
 * file-backed and disposable with the parent session directory.
 */
final class AgentArtifactRegistry
{
    private const SCHEMA_VERSION = 1;

    public function __construct(
        private readonly AgentArtifactPathResolver $pathResolver,
        private readonly NormalizerInterface&DenormalizerInterface $serializer,
        private readonly ValidatorInterface $validator,
        private readonly LockFactory $lockFactory,
    ) {
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
     * List all artifact entries for a parent session.
     *
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

    /**
     * Resolve the absolute base path for a parent session's agent artifacts.
     *
     * @deprecated Prefer {@see AgentArtifactPathResolver::resolveArtifactsBasePath()}.
     *             Kept for backward compatibility with existing public API consumers.
     */
    public function resolveArtifactsBasePath(string $parentRunId): string
    {
        return $this->pathResolver->resolveArtifactsBasePath($parentRunId);
    }

    /**
     * Resolve an absolute path to a child artifact directory.
     *
     * @deprecated Prefer {@see AgentArtifactPathResolver::resolveArtifactDir()}.
     *             Kept for backward compatibility with existing public API consumers.
     */
    public function resolveArtifactDir(string $parentRunId, string $artifactId): string
    {
        return $this->pathResolver->resolveArtifactDir($parentRunId, $artifactId);
    }

    // ── Internal read/write methods ─────────────────────────────────────

    /**
     * Load all entries from registry.json for a parent session.
     *
     * A missing file is legitimate empty.  Corrupt JSON or an
     * unsupported schema version throw — never silently return [].
     *
     * @return list<AgentArtifactEntryDTO>
     *
     * @throws \RuntimeException when the registry file is corrupt
     */
    private function loadRegistry(string $parentRunId): array
    {
        $path = $this->pathResolver->registryPath($parentRunId);

        if (!is_readable($path)) {
            return [];
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
        $dir = \dirname($path);

        if (!is_dir($dir)) {
            mkdir($dir, AgentArtifactPathResolver::DIR_PERMISSIONS, true);
        }

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

        // Temp-file + rename for atomic replacement.
        $tmpPath = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $written = file_put_contents($tmpPath, $json, \LOCK_EX);
        if (false === $written) {
            throw new \RuntimeException(\sprintf('Failed to write registry.json for parent run "%s".', $parentRunId));
        }
        chmod($tmpPath, 0644);
        rename($tmpPath, $path);
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
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, AgentArtifactPathResolver::DIR_PERMISSIONS, true);
        }

        $json = json_encode($this->normalizeEntry($entry), \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR);
        $tmpPath = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $written = file_put_contents($tmpPath, $json, \LOCK_EX);
        if (false === $written) {
            throw new \RuntimeException(\sprintf('Failed to write metadata.json for artifact "%s" parent "%s".', $entry->artifactId, $parentRunId));
        }
        chmod($tmpPath, 0644);
        rename($tmpPath, $path);
    }

    /**
     * Write (or overwrite) the handoff.md file for a child artifact.
     *
     * An empty string creates an empty file placeholder so the path
     * reference is always valid.  Uses atomic temp-file + rename to
     * avoid partial writes.
     */
    private function writeHandoff(string $parentRunId, string $artifactId, string $content): void
    {
        $paths = AgentArtifactPathsDTO::forArtifactId($artifactId);
        $path = $this->pathResolver->absolutePath($parentRunId, $paths->handoffPath);
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, AgentArtifactPathResolver::DIR_PERMISSIONS, true);
        }

        $tmpPath = $path.'.'.bin2hex(random_bytes(4)).'.tmp';
        $written = file_put_contents($tmpPath, $content, \LOCK_EX);
        if (false === $written) {
            throw new \RuntimeException(\sprintf('Failed to write handoff.md for artifact "%s" parent "%s".', $artifactId, $parentRunId));
        }
        chmod($tmpPath, 0644);
        rename($tmpPath, $path);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /**
     * Ensure the artifact directory exists for a given parent + artifact ID.
     */
    private function ensureArtifactDir(string $parentRunId, string $artifactId): void
    {
        $path = $this->pathResolver->resolveArtifactDir($parentRunId, $artifactId);
        if (!is_dir($path)) {
            mkdir($path, AgentArtifactPathResolver::DIR_PERMISSIONS, true);
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

        // Validate required identity fields are present and non-empty after denormalization.
        if ('' === $entry->artifactId || '' === $entry->parentRunId || '' === $entry->agentRunId || '' === $entry->agentName) {
            throw new \RuntimeException(\sprintf('Registry entry for parent run "%s" has empty required identity fields.', $parentRunId));
        }

        // Validate stored paths match the canonical paths for this artifact ID.
        $expectedPaths = AgentArtifactPathsDTO::forArtifactId($entry->artifactId);
        if ($entry->paths->handoffPath !== $expectedPaths->handoffPath
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
