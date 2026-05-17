<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Ineersa\CodingAgent\Config\AppConfig;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Yaml\Yaml;

final class HatfieldSessionStore
{
    private readonly LockFactory $lockFactory;

    public function __construct(
        private readonly AppConfig $appConfig,
    ) {
        $this->lockFactory = new LockFactory(new FlockStore());
    }

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

    public function loadMetadata(string $sessionId): ?array
    {
        $path = $this->getSessionDir($sessionId).'/metadata.yaml';

        if (!is_readable($path)) {
            return null;
        }

        $data = Yaml::parseFile($path);

        return \is_array($data) ? $data : null;
    }

    public function updateMetadata(string $sessionId, array $meta): void
    {
        $existing = $this->loadMetadata($sessionId) ?? [];
        $merged = array_merge($existing, $meta);
        $merged['updated_at'] = date('c');

        $lock = $this->lockFactory->createLock('hatfield-session-'.$sessionId);
        try {
            $lock->acquire(true);
            file_put_contents($this->getSessionDir($sessionId).'/metadata.yaml', Yaml::dump($merged, 4, 2));
        } finally {
            $lock->release();
        }
    }

    public function appendTranscriptEntry(string $sessionId, TranscriptEntry $entry): void
    {
        $path = $this->getSessionDir($sessionId).'/transcript.jsonl';
        $lock = $this->lockFactory->createLock('hatfield-session-'.$sessionId);

        try {
            $lock->acquire(true);
            $this->ensureSessionDir($sessionId);
            file_put_contents($path, json_encode($entry->toArray(), \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n", \FILE_APPEND | \LOCK_EX);
        } finally {
            $lock->release();
        }
    }

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

    public function appendRuntimeEvent(string $sessionId, array $event): void
    {
        $path = $this->getSessionDir($sessionId).'/runtime-events.jsonl';
        $lock = $this->lockFactory->createLock('hatfield-session-'.$sessionId);

        try {
            $lock->acquire(true);
            $this->ensureSessionDir($sessionId);
            file_put_contents($path, json_encode($event, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)."\n", \FILE_APPEND | \LOCK_EX);
        } finally {
            $lock->release();
        }
    }

    public function exists(string $sessionId): bool
    {
        return is_readable($this->getSessionDir($sessionId).'/metadata.yaml');
    }

    public function generateId(): string
    {
        return $this->generateSessionId();
    }

    public function resolveSessionsBasePath(): string
    {
        return $this->getSessionsDir();
    }

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

    private function generateSessionId(): string
    {
        return bin2hex(random_bytes(6));
    }
}
