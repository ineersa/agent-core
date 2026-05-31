<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\CodingAgent\Config\AppConfig;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Yaml\Yaml;

/**
 * Filesystem-backed session persistence using the Hatfield config system.
 *
 * Sessions are stored under the configured sessions.path (defaults to
 * .hatfield/sessions/). Each session is a directory containing:
 *
 *   <session-id>/
 *     metadata.yaml       Session metadata (session_id, run_id, parent_id, etc.)
 *     state.json          AgentCore RunState hot state cache (via SessionRunStore)
 *     events.jsonl        AgentCore RunEvent canonical stream (via SessionRunEventStore)
 *     transcript.jsonl    Append-only TUI transcript projection
 *     transcript.jsonl    Append-only TUI transcript projection
 *
 * session_id === run_id in Hatfield. One directory equals one session
 * equals one agent run equals one future fork tree node.
 *
 * All writes are protected by a Symfony Lock (FlockStore) to prevent
 * concurrent corruption from multiple processes.
 */
final class HatfieldSessionStore
{
    public function __construct(
        private readonly AppConfig $appConfig,
        private readonly LockFactory $lockFactory,
    ) {
    }

    /**
     * Create a new session directory and return its ID.
     *
     * When $sessionId is provided, it becomes both the session ID and the
     * agent-core run ID. When empty, a new 12-char hex ID is generated
     * with collision checking (loops until a non-existing ID is found).
     *
     * Session ID collision is explicitly validated:
     * - If an explicit $sessionId is provided and already exists, a
     *   \RuntimeException is thrown.
     * - If a generated ID collides, the loop retries.
     * - session_id === run_id in Hatfield.
     *
     * The on-disk layout is self-contained for resume and future forking:
     *   metadata.yaml (session_id, run_id, parent_id, root_id, etc.)
     *   state.json (empty, written by SessionRunStore on first CAS)
     *   events.jsonl (empty)
     *   transcript.jsonl (empty)
     *
     * @param string $prompt    Optional initial prompt for metadata
     * @param string $sessionId Optional pre-generated ID; auto-generated if empty
     *
     * @return string The session/run ID
     */
    public function createSession(string $prompt = '', string $sessionId = ''): string
    {
        if ('' === $sessionId) {
            $sessionId = $this->generateSessionId(false);
        } else {
            // When an explicit session ID is provided, verify it does not already exist.
            // This prevents silent overwrite of an existing session directory.
            if ($this->exists($sessionId)) {
                throw new \RuntimeException(\sprintf('Cannot create session "%s": a session with this ID already exists.', $sessionId));
            }
        }

        $sessionPath = $this->getSessionDir($sessionId);
        $lock = $this->lockFactory->createLock('hatfield-session-'.$sessionId);

        try {
            $lock->acquire(true);

            if (!is_dir($sessionPath)) {
                mkdir($sessionPath, 0777, true);
            }

            // session_id === run_id in Hatfield
            $metadata = [
                'session_id' => $sessionId,
                'run_id' => $sessionId,
                'parent_id' => null,
                'root_id' => null,
                'created_at' => date('c'),
                'updated_at' => date('c'),
                'cwd' => $this->appConfig->cwd,
                'prompt' => $prompt,
            ];
            file_put_contents($sessionPath.'/metadata.yaml', Yaml::dump($metadata, 4, 2));

            file_put_contents($sessionPath.'/state.json', '');
            file_put_contents($sessionPath.'/events.jsonl', '');
            file_put_contents($sessionPath.'/transcript.jsonl', '');

            chmod($sessionPath.'/state.json', 0644);
            chmod($sessionPath.'/events.jsonl', 0644);
            chmod($sessionPath.'/transcript.jsonl', 0644);
        } finally {
            $lock->release();
        }

        return $sessionId;
    }

    /**
     * Load session metadata.
     *
     * @return array<string, mixed>|null Null if session doesn't exist
     */
    public function loadMetadata(string $sessionId): ?array
    {
        $path = $this->getSessionDir($sessionId).'/metadata.yaml';

        if (!is_readable($path)) {
            return null;
        }

        $data = Yaml::parseFile($path);

        return \is_array($data) ? $data : null;
    }

    /**
     * Update session metadata.
     *
     * @param array<string, mixed> $meta
     */
    public function updateMetadata(string $sessionId, array $meta): void
    {
        $existing = $this->loadMetadata($sessionId) ?? [];
        $merged = array_merge($existing, $meta);
        $merged['updated_at'] = date('c');

        $lock = $this->lockFactory->createLock('hatfield-session-'.$sessionId);
        try {
            $lock->acquire(true);
            file_put_contents(
                $this->getSessionDir($sessionId).'/metadata.yaml',
                Yaml::dump($merged, 4, 2),
            );
        } finally {
            $lock->release();
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
     * Check whether a session exists.
     */
    public function exists(string $sessionId): bool
    {
        return is_readable($this->getSessionDir($sessionId).'/metadata.yaml');
    }

    /**
     * Generate a unique session/run ID without creating a directory.
     *
     * Used by InteractiveMode to pre-generate the ID before passing it
     * to both createSession() and StartRunRequest, ensuring session_id === run_id.
     *
     * @return string 12-char hex ID
     */
    public function generateId(): string
    {
        return $this->generateSessionId();
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
     * Build the base sessions directory path from resolved config.
     *
     * Reads the typed {@see SessionsConfig} from AppConfig. In production
     * the path is absolute (resolved by {@see AppConfigLoader}). For tests
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

    /**
     * Generate a unique session ID that does not already exist on disk.
     *
     * When $checkExisting is true (default), loops until a non-existing
     * ID is found. This prevents collision with existing sessions.
     *
     * @param bool $checkExisting When true, verify the ID does not collide with an existing session
     *
     * @return string 12-char hex ID
     */
    private function generateSessionId(bool $checkExisting = true): string
    {
        do {
            // 12-char hex ID, same style as agent-core run IDs
            $id = bin2hex(random_bytes(6));
        } while ($checkExisting && $this->exists($id));

        return $id;
    }
}
