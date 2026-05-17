<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\CodingAgent\Config\AppConfig;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
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
 *     runtime-events.jsonl Append-only runtime protocol event log
 *
 * session_id === run_id in Hatfield. One directory equals one session
 * equals one agent run equals one future fork tree node.
 *
 * All writes are protected by a Symfony Lock (FlockStore) to prevent
 * concurrent corruption from multiple processes.
 */
final class HatfieldSessionStore
{
    private readonly LockFactory $lockFactory;

    public function __construct(
        private readonly AppConfig $appConfig,
    ) {
        $this->lockFactory = new LockFactory(new FlockStore());
    }

    /**
     * Create a new session directory and return its ID.
     *
     * When $sessionId is provided, it becomes both the session ID and the
     * agent-core run ID. When empty, a new 12-char hex ID is generated.
     *
     * The on-disk layout is self-contained for resume and future forking:
     *   metadata.yaml (session_id, run_id, parent_id, root_id, etc.)
     *   state.json (empty, written by SessionRunStore on first CAS)
     *   events.jsonl (empty)
     *   transcript.jsonl (empty)
     *   runtime-events.jsonl (empty)
     *
     * @param string $prompt    Optional initial prompt for metadata
     * @param string $sessionId Optional pre-generated ID; auto-generated if empty
     *
     * @return string The session/run ID
     */
    public function createSession(string $prompt = '', string $sessionId = ''): string
    {
        if ('' === $sessionId) {
            $sessionId = $this->generateSessionId();
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
            file_put_contents($sessionPath.'/runtime-events.jsonl', '');

            chmod($sessionPath.'/state.json', 0644);
            chmod($sessionPath.'/events.jsonl', 0644);
            chmod($sessionPath.'/transcript.jsonl', 0644);
            chmod($sessionPath.'/runtime-events.jsonl', 0644);
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
     * Append a runtime protocol event to a session.
     */
    public function appendRuntimeEvent(string $sessionId, array $event): void
    {
        $path = $this->getSessionDir($sessionId).'/runtime-events.jsonl';
        $lock = $this->lockFactory->createLock('hatfield-session-'.$sessionId);

        try {
            $lock->acquire(true);
            $this->ensureSessionDir($sessionId);
            file_put_contents(
                $path,
                json_encode($event, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n",
                \FILE_APPEND | \LOCK_EX,
            );
        } finally {
            $lock->release();
        }
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
     * Falls back to <cwd>/.hatfield/sessions when sessions.path is not
     * explicitly configured. Relative paths resolve against the active
     * project directory ({@see AppConfig::$cwd}).
     */
    private function getSessionsDir(): string
    {
        $path = (string) ($this->appConfig->sessions['path'] ?? '');
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
     * Generate a unique session ID.
     */
    private function generateSessionId(): string
    {
        // 12-char hex ID, same style as agent-core run IDs
        return bin2hex(random_bytes(6));
    }
}
