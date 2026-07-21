<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Entity\HatfieldSessionRepository;

use function Symfony\Component\String\u;

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
 * A user-visible display name (`name`), initialized from the first user
 * message and later customizable via `/rename`.
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
final class HatfieldSessionStore implements SessionExistenceCheckerInterface
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
     * integer ID, generates the initial session name from the prompt
     * (trimmed, collapsed to one line, capped at 200 chars), then
     * creates the session directory under .hatfield/sessions/<id>/
     * containing state.json and events.jsonl (both empty).
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
        $session->name = $this->resolveDefaultName($prompt);

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
     * Load the persisted session row by public session id (numeric string).
     *
     * Callers must treat the returned entity as read-only; mutations and flush
     * belong on HatfieldSessionStore write APIs (updateMetadata, createSession).
     */
    public function findSession(string $sessionId): ?HatfieldSession
    {
        return $this->fetchEntityOrNull($sessionId);
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
            if (\is_string($meta['name'])) {
                $name = u($meta['name'])
                    ->trim()
                    ->replaceMatches('/\s+/u', ' ')
                    ->truncate(200, '');
                $nameStr = $name->toString();
                $entity->name = '' !== $nameStr ? $nameStr : 'Session';
            } else {
                $entity->name = 'Session';
            }
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
     * Return all sessions with metadata suitable for picker/catalog display,
     * sorted by updated_at DESC (most recent first).
     *
     * Delegates the DB query to HatfieldSessionRepository::findForCatalog()
     * and enriches each row with a computed, non-persisted promptPreview.
     * The `name` field is guaranteed non-empty (generated from the first user
     * message or set to the fallback "Session"); `displayTitle` equals `name`.
     *
     * @return list<array{
     *     sessionId: string,
     *     name: string,
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
    public function listSessions(): array
    {
        $entities = $this->getRepository()->findForCatalog();
        $result = [];

        foreach ($entities as $entity) {
            $id = (string) $entity->id;
            $name = $entity->name;
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
     * Ensure the session attachments directory exists and return its absolute path.
     *
     * Pasted images from the TUI are stored here for replay/resume (issue #119).
     */
    public function ensureSessionAttachmentsDirectory(string $sessionId): string
    {
        $sessionDir = $this->getSessionDir($sessionId);
        $attachments = $sessionDir.'/attachments';
        if (!is_dir($attachments)) {
            if (!@mkdir($attachments, 0o700, true) && !is_dir($attachments)) {
                throw new \RuntimeException(\sprintf('Failed to create session attachments directory for session "%s".', $sessionId));
            }
        }
        @chmod($attachments, 0o700);

        return $attachments;
    }

    /**
     * Compute the user-visible display title without mutating the DB.
     *
     * Accepts an already-computed prompt preview so the caller avoids
     * duplicate truncation when also storing the preview.
     *
     * Fallback order: name → prompt preview → "Session <id>".
     * The name branch is always taken in normal operation (name is
     * guaranteed non-empty by createSession and updateMetadata); the
     * other branches are defensive only.
     */
    private function resolveDisplayTitle(string $sessionId, string $name, ?string $promptPreview): string
    {
        if ('' !== $name) {
            return $name;
        }

        if (null !== $promptPreview) {
            return $promptPreview;
        }

        return "Session {$sessionId}";
    }

    /**
     * Build a graphene-safe truncated prompt preview via Symfony String.
     *
     * Returns null when the prompt is empty or null.
     */
    private function resolvePromptPreview(?string $prompt): ?string
    {
        if (null === $prompt || '' === $prompt) {
            return null;
        }

        return u($prompt)->truncate(60, '...')->toString();
    }

    /**
     * Generate the initial session name from the first user message.
     *
     * Trims leading/trailing whitespace, collapses internal whitespace/
     * newlines to single spaces, and truncates to 200 characters
     * (grapheme-safe via Symfony String).  Empty/whitespace-only prompts
     * receive the deterministic fallback "Session".
     */
    private function resolveDefaultName(?string $prompt): string
    {
        if (null === $prompt || '' === trim($prompt)) {
            return 'Session';
        }

        $name = u($prompt)
            ->trim()
            ->replaceMatches('/\s+/u', ' ')
            ->truncate(200, '');

        $nameStr = $name->toString();

        if ('' === $nameStr) {
            return 'Session';
        }

        return $nameStr;
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
