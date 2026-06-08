<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Entity\HatfieldSessionRepository;

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
 *     (no separate projection file — transcript rebuilt from events.jsonl)
 *
 * Session metadata (identity, prompt, model, reasoning, name,
 * fork tree links) lives in the hatfield_session DB table — not in a
 * metadata.yaml file.  The DB row is the canonical source of truth for
 * session identity.
 *
 * An optional user-visible display name (the `name` column) can be set
 * via /rename.  Unnamed sessions use a deterministic fallback: the
 * truncated initial prompt, or `Session <id>` when no prompt exists.
 *
 * session_id === run_id in Hatfield. One directory equals one session
 * equals one agent run equals one future fork tree node.
 *
 * Session IDs are DB-issued auto-increment integers converted to strings.
 * The integer primary key in hatfield_session is the canonical identifier;
 * external representations (session_id / runId) use its string form.
 * There is no separate public_id column.
 *
 * Session metadata updates are persisted via Doctrine ORM with its own
 * transactional guarantees. State and event files are locked independently
 * by their respective stores (SessionRunStore, SessionRunEventStore).
 */
final class HatfieldSessionStore
{
    public function __construct(
        private readonly AppConfig $appConfig,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Create a new session, insert a DB metadata row, and return its ID.
     *
     * Inserts a HatfieldSession entity to obtain an auto-increment
     * integer ID, then creates the session directory under
     * .hatfield/sessions/<id>/ containing state.json and events.jsonl
     * (all empty).
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
            'created_at' => $entity->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $entity->updatedAt->format(\DateTimeInterface::ATOM),
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
        if (null !== $entity->name) {
            $meta['name'] = $entity->name;
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
        if (\array_key_exists('name', $meta)) {
            $entity->name = \is_string($meta['name'])
                ? ('' !== trim($meta['name']) ? trim($meta['name']) : null)
                : null;
            $dirty = true;
        }

        if ($dirty) {
            $this->entityManager->flush();
        }
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
     * Return recent sessions with metadata suitable for picker/catalog display.
     *
     * Delegates the DB query to HatfieldSessionRepository::findForCatalog()
     * and enriches each row with a computed, non-persisted displayTitle.
     * The display fallback is deterministic and does not mutate the DB:
     *   1. Explicit name (non-empty trimmed)
     *   2. Truncated prompt preview (multibyte-safe, 60 chars + ellipsis)
     *   3. "Session <id>"
     *
     * @param string $sortBy External key: 'updated_at', 'created_at', 'prompt', or 'name'
     * @param int    $limit  Max results (1..200)
     * @param string $order  'ASC' or 'DESC'
     *
     * @return list<array{
     *     sessionId: string,
     *     name: ?string,
     *     displayTitle: string,
     *     cwd: string,
     *     prompt: ?string,
     *     promptPreview: ?string,
     *     model: ?string,
     *     model_provider: ?string,
     *     model_name: ?string,
     *     reasoning: ?string,
     *     created_at: string,
     *     updated_at: string,
     * }>
     */
    public function listSessions(
        string $sortBy = 'updated_at',
        int $limit = 50,
        string $order = 'DESC',
    ): array {
        $entities = $this->getRepository()->findForCatalog($sortBy, $limit, $order);
        $result = [];

        foreach ($entities as $entity) {
            $id = (string) $entity->id;
            $name = null !== $entity->name && '' !== trim($entity->name)
                ? trim($entity->name)
                : null;
            $promptPreview = $this->resolvePromptPreview($entity->prompt);
            $displayTitle = $this->resolveDisplayTitle($id, $name, $promptPreview);

            $result[] = [
                'sessionId' => $id,
                'name' => $name,
                'displayTitle' => $displayTitle,
                'cwd' => $entity->cwd,
                'prompt' => $entity->prompt,
                'promptPreview' => $promptPreview,
                'model' => $entity->model,
                'model_provider' => $entity->modelProvider,
                'model_name' => $entity->modelName,
                'reasoning' => $entity->reasoning,
                'created_at' => $entity->createdAt->format(\DateTimeInterface::ATOM),
                'updated_at' => $entity->updatedAt->format(\DateTimeInterface::ATOM),
            ];
        }

        return $result;
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
     * Compute the user-visible display title without mutating the DB.
     *
     * Accepts an already-computed prompt preview so the caller avoids
     * duplicate mb_strimwidth work when also storing the preview.
     *
     * Fallback order: explicit name → prompt preview → "Session <id>".
     */
    private function resolveDisplayTitle(string $sessionId, ?string $name, ?string $promptPreview): string
    {
        if (null !== $name && '' !== $name) {
            return $name;
        }

        if (null !== $promptPreview) {
            return $promptPreview;
        }

        return "Session {$sessionId}";
    }

    /**
     * Build a multibyte-safe truncated prompt preview.
     *
     * Returns null when the prompt is empty or null.
     */
    private function resolvePromptPreview(?string $prompt): ?string
    {
        if (null === $prompt || '' === $prompt) {
            return null;
        }

        $preview = mb_strimwidth($prompt, 0, 60, '...');

        return $preview;
    }

    /**
     * Write session files (state.json, events.jsonl).
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

        chmod($sessionPath.'/state.json', 0644);
        chmod($sessionPath.'/events.jsonl', 0644);
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
     * Retrieve the HatfieldSessionRepository from the EntityManager.
     *
     * Lazy accessor avoids a constructor dependency that would force
     * test callers to provide a mockable (non-final) repository.
     */
    private function getRepository(): HatfieldSessionRepository
    {
        $repo = $this->entityManager->getRepository(HatfieldSession::class);
        \assert($repo instanceof HatfieldSessionRepository);

        return $repo;
    }
}
