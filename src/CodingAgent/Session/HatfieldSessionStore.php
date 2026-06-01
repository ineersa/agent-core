<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
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
 * Session IDs are DB-issued auto-increment integers converted to strings.
 * The hatfield_session table acts as an authoritative ID registry; the
 * filesystem under .hatfield/sessions/ is the canonical storage.
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
     * Create a new session directory and return its DB-issued ID.
     *
     * Inserts a HatfieldSession entity to obtain an auto-increment
     * integer ID, then creates the session files under
     * .hatfield/sessions/<id>/.
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
