<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Symfony\Component\Lock\LockFactory;

/**
 * Session persistence backed by the hatfield_session DB table and the
 * Hatfield config filesystem.
 *
 * Sessions are stored under the configured sessions.path (defaults to
 * .hatfield/sessions/). Each session is a directory containing:
 *
 *   <session-id>/
 *     state.json          AgentCore RunState hot state cache (via SessionRunStore)
 *     events.jsonl        AgentCore RunEvent canonical stream (via SessionRunEventStore)
 *     transcript.jsonl    Append-only TUI transcript projection
 *
 * Session metadata (identity, prompt, model, reasoning, fork tree links)
 * lives in the hatfield_session DB table — not in a metadata.yaml file.
 * The DB row is the canonical source of truth for session identity.
 *
 * session_id === run_id in Hatfield. One directory equals one session
 * equals one agent run equals one future fork tree node.
 *
 * Session IDs are DB-issued auto-increment integers converted to strings.
 * The integer primary key in hatfield_session is the canonical identifier;
 * external representations (session_id / runId) use its string form.
 * There is no separate public_id column.
 *
 * All writes are protected by a Symfony Lock (FlockStore) to prevent
 * concurrent corruption from multiple processes.
 */
final class HatfieldSessionStore
{
    public function __construct(
        private readonly AppConfig $appConfig,
        private readonly LockFactory $lockFactory,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Create a new session, insert a DB metadata row, and return its ID.
     *
     * Inserts a HatfieldSession entity to obtain an auto-increment
     * integer ID, then creates the session directory under
     * .hatfield/sessions/<id>/ containing state.json, events.jsonl,
     * and transcript.jsonl (all empty).
     *
     * Metadata is the DB row — no metadata.yaml is written.
     *
     * If file creation fails after DB insert, the session row is
     * removed from the DB to avoid silently inconsistent state.
     *
     * @param string $prompt Optional initial prompt for metadata
     *
     * @return string The session/run ID (numeric string)
     *
     * @throws \RuntimeException if session creation cannot complete
     */
    public function createSession(string $prompt = ''): string
    {
        $session = new HatfieldSession();
        $session->cwd = $this->appConfig->cwd;
        $session->prompt = '' !== $prompt ? $prompt : null;

        $this->entityManager->persist($session);
        $this->entityManager->flush();

        // ID is the DB-issued auto-increment integer, used as
        // both the string session ID and the AgentCore runId.
        $sessionId = (string) $session->id;

        try {
            $this->writeSessionFiles($sessionId, $prompt);
        } catch (\Throwable $e) {
            // Roll back the DB row — no silently inconsistent state.
            try {
                $this->entityManager->remove($session);
                $this->entityManager->flush();
            } catch (\Throwable $cleanup) {
                // Best-effort cleanup; log would be better but we avoid
                // a logger dependency to keep this class focused.
                throw new \RuntimeException(\sprintf('Failed to create session files for ID "%s" and cleanup also failed: %s. Original error: %s', $sessionId, $cleanup->getMessage(), $e->getMessage()), 0, $e);
            }

            throw new \RuntimeException(\sprintf('Failed to create session files for ID "%s": %s', $sessionId, $e->getMessage()), 0, $e);
        }

        return $sessionId;
    }

    /**
     * Load session metadata from the database.
     *
     * Returns the same array shape callers expect: session_id, run_id,
     * parent_id, root_id, created_at, updated_at, cwd, prompt, model,
     * model_provider, model_name, reasoning. Only returns keys with
     * non-null values (except session_id/run_id/cwd/created_at/updated_at
     * which are always present).
     *
     * @return array<string, mixed>|null Null if the session row does not exist
     */
    public function loadMetadata(string $sessionId): ?array
    {
        $entity = $this->fetchEntityOrNull($sessionId);
        if (null === $entity) {
            return null;
        }

        $id = (string) $entity->id;
        $meta = [
            'session_id' => $id,
            'run_id' => $id,
            'created_at' => $entity->createdAt,
            'updated_at' => $entity->updatedAt,
            'cwd' => $entity->cwd,
        ];

        if (null !== $entity->parentId) {
            $meta['parent_id'] = $entity->parentId;
        } else {
            $meta['parent_id'] = null;
        }
        if (null !== $entity->rootId) {
            $meta['root_id'] = $entity->rootId;
        } else {
            $meta['root_id'] = null;
        }
        if (null !== $entity->prompt) {
            $meta['prompt'] = $entity->prompt;
        }
        if (null !== $entity->model) {
            $meta['model'] = $entity->model;
        }
        if (null !== $entity->modelProvider) {
            $meta['model_provider'] = $entity->modelProvider;
        }
        if (null !== $entity->modelName) {
            $meta['model_name'] = $entity->modelName;
        }
        if (null !== $entity->reasoning) {
            $meta['reasoning'] = $entity->reasoning;
        }

        return $meta;
    }

    /**
     * Update session metadata fields on the database row.
     *
     * Creates a new HatfieldSession entity when one does not yet exist
     * given session ID. Known keys are mapped to entity fields;
     * unknown keys are silently ignored. Does nothing when no
     * matching session row exists — session creation is always
     * through createSession().
     *
     * @param array<string, mixed> $meta
     */
    public function updateMetadata(string $sessionId, array $meta): void
    {
        $entity = $this->fetchEntityOrNull($sessionId);

        if (null === $entity) {
            // No matching session row — update is a no-op.
            // Session rows are only created by createSession().
            return;
        }

        $dirty = false;

        if (\array_key_exists('prompt', $meta) && \is_string($meta['prompt'])) {
            $entity->prompt = $meta['prompt'];
            $dirty = true;
        }
        if (\array_key_exists('model', $meta) && \is_string($meta['model'])) {
            $entity->model = $meta['model'];
            $dirty = true;
        }
        if (\array_key_exists('model_provider', $meta) && \is_string($meta['model_provider'])) {
            $entity->modelProvider = $meta['model_provider'];
            $dirty = true;
        }
        if (\array_key_exists('model_name', $meta) && \is_string($meta['model_name'])) {
            $entity->modelName = $meta['model_name'];
            $dirty = true;
        }
        if (\array_key_exists('reasoning', $meta) && \is_string($meta['reasoning'])) {
            $entity->reasoning = $meta['reasoning'];
            $dirty = true;
        }
        if (\array_key_exists('parent_id', $meta)) {
            $entity->parentId = \is_string($meta['parent_id']) ? $meta['parent_id'] : null;
            $dirty = true;
        }
        if (\array_key_exists('root_id', $meta)) {
            $entity->rootId = \is_string($meta['root_id']) ? $meta['root_id'] : null;
            $dirty = true;
        }
        if (\array_key_exists('cwd', $meta) && \is_string($meta['cwd'])) {
            $entity->cwd = $meta['cwd'];
            $dirty = true;
        }

        if ($dirty) {
            $this->entityManager->flush();
        }
    }

    /**
     * Append a transcript entry to a session.
     */
    public function appendTranscriptEntry(string $sessionId, TranscriptEntry $entry): void
    {
        $path = $this->getSessionDir($sessionId).'/transcript.jsonl';
        $lock = $this->lockFactory->createLock('hatfield-session-'.$sessionId);

        try {
            $lock->acquire(true);
            $this->ensureSessionDir($sessionId);
            file_put_contents(
                $path,
                json_encode($entry->toArray(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n",
                \FILE_APPEND | \LOCK_EX,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * Load all transcript entries for a session, in order.
     *
     * @return list<TranscriptEntry>
     */
    public function getTranscript(string $sessionId): array
    {
        $path = $this->getSessionDir($sessionId).'/transcript.jsonl';

        if (!is_readable($path)) {
            return [];
        }

        $entries = [];
        $lines = file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return [];
        }

        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if (\is_array($data)) {
                $entries[] = TranscriptEntry::fromArray($data);
            }
        }

        return $entries;
    }

    /**
     * Check whether a session exists by looking up its database row.
     *
     * Only DB-issued numeric session IDs are valid. Non-numeric IDs
     * (e.g., old-style hex strings from pre-DB sessions) always return false.
     */
    public function exists(string $sessionId): bool
    {
        return null !== $this->fetchEntityOrNull($sessionId);
    }

    /**
     * Resolve the sessions base path from Hatfield config.
     *
     * Uses sessions.path from the fully resolved config (after defaults,
     * home, and project layer overlay). Falls back to
     * <cwd>/.hatfield/sessions when no explicit path is configured.
     */
    public function resolveSessionsBasePath(): string
    {
        return $this->getSessionsDir();
    }

    /**
     * Write session files (state.json, events.jsonl, transcript.jsonl).
     *
     * Session metadata is the DB row; no metadata.yaml is written.
     */
    private function writeSessionFiles(string $sessionId, string $prompt): void
    {
        $sessionPath = $this->getSessionDir($sessionId);

        if (!is_dir($sessionPath)) {
            mkdir($sessionPath, 0777, true);
        }

        file_put_contents($sessionPath.'/state.json', '');
        file_put_contents($sessionPath.'/events.jsonl', '');
        file_put_contents($sessionPath.'/transcript.jsonl', '');

        chmod($sessionPath.'/state.json', 0644);
        chmod($sessionPath.'/events.jsonl', 0644);
        chmod($sessionPath.'/transcript.jsonl', 0644);
    }

    /**
     * Fetch a HatfieldSession entity by its string session ID.
     *
     * Parses the session ID as an integer and looks up the
     * auto-increment primary key directly via the EntityManager.
     * Non-numeric IDs always return null without any fallback.
     */
    private function fetchEntityOrNull(string $sessionId): ?HatfieldSession
    {
        // Only numeric IDs are valid; cast to int for primary-key lookup.
        // Non-numeric strings -> (int) -> 0, which never matches a real
        // auto-increment ID (starts at 1 in SQLite).
        $id = (int) $sessionId;
        if (0 === $id) {
            return null;
        }

        return $this->entityManager->find(HatfieldSession::class, $id);
    }

    /**
     * Build the base sessions directory path from resolved config.
     *
     * Reads the typed SessionsConfig from AppConfig. In production
     * the path is absolute (resolved by AppConfigLoader). For tests
     * that construct AppConfig directly with a relative path, we resolve
     * against the active project directory.
     */
    private function getSessionsDir(): string
    {
        $path = $this->appConfig->sessions->path;
        $cwd = $this->appConfig->cwd;

        if ('' === $path) {
            $path = $cwd.'/.hatfield/sessions';
        }

        if (!str_starts_with($path, '/')) {
            $path = $cwd.'/'.$path;
        }

        return $path;
    }

    /**
     * Get the full path to a session directory.
     */
    private function getSessionDir(string $sessionId): string
    {
        return $this->getSessionsDir().'/'.$sessionId;
    }

    /**
     * Ensure the session directory exists (create if needed).
     */
    private function ensureSessionDir(string $sessionId): void
    {
        $dir = $this->getSessionDir($sessionId);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}
