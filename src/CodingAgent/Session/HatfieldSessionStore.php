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
 * .hatfield/sessions/). Each session is a directory.
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
     * When $sessionId is provided, it becomes both the session ID and
     * run ID. When empty, a new 12-char hex ID is generated.
     *
     * Collision checking is performed under the session lock:
     * - For explicit IDs: acquires lock, checks existence, throws if
     *   the session already exists.
     * - For generated IDs: loops generating candidate IDs, acquiring
     *   the per-candidate lock, checking existence, and retrying on
     *   collision until a free ID is found.
     *
     * Once a free ID is confirmed under lock, the session directory
     * and metadata files are created before the lock is released.
     *
     * @param string $prompt    Optional initial prompt for metadata
     * @param string $sessionId Optional pre-generated ID; auto-generated if empty
     *
     * @return string The session/run ID
     *
     * @throws \RuntimeException if the explicit $sessionId already exists
     */
    public function createSession(string $prompt = '', string $sessionId = ''): string
    {
        $hasExplicitId = '' !== $sessionId;

        if ($hasExplicitId) {
            // Explicit ID: acquire lock, check existence under lock.
            $lock = $this->lockFactory->createLock('hatfield-session-'.$sessionId);
            $lock->acquire(true);

            try {
                if ($this->exists($sessionId)) {
                    throw new \RuntimeException(\sprintf('Cannot create session "%s": a session with this ID already exists.', $sessionId));
                }

                $this->writeSessionFiles($sessionId, $prompt);
            } finally {
                $lock->release();
            }
        } else {
            // Generated ID: loop until we find a non-existing ID, checking
            // under each candidate's lock so two concurrent generators cannot
            // claim the same ID.
            while (true) {
                $candidate = bin2hex(random_bytes(6));

                $lock = $this->lockFactory->createLock('hatfield-session-'.$candidate);
                $lock->acquire(true);

                if ($this->exists($candidate)) {
                    $lock->release();
                    continue;
                }

                try {
                    $this->writeSessionFiles($candidate, $prompt);
                    $sessionId = $candidate;
                    break;
                } finally {
                    $lock->release();
                }
            }
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
     * This method checks existence without holding a lock. The definitive
     * collision check happens under lock inside createSession().
     *
     * @return string 12-char hex ID
     */
    public function generateId(): string
    {
        do {
            $id = bin2hex(random_bytes(6));
        } while ($this->exists($id));

        return $id;
    }

    /**
     * Resolve the sessions base path from Hatfield config.
     */
    public function resolveSessionsBasePath(): string
    {
        return $this->getSessionsDir();
    }

    /**
     * Write session files under an already-held lock.
     */
    private function writeSessionFiles(string $sessionId, string $prompt): void
    {
        $sessionPath = $this->getSessionDir($sessionId);

        if (!is_dir($sessionPath)) {
            mkdir($sessionPath, 0777, true);
        }

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
    }

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

    private function getSessionDir(string $sessionId): string
    {
        return $this->getSessionsDir().'/'.$sessionId;
    }

    private function ensureSessionDir(string $sessionId): void
    {
        $dir = $this->getSessionDir($sessionId);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
    }
}
