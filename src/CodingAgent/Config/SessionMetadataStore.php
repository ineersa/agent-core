<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Symfony\Component\Yaml\Yaml;

/**
 * File-backed session metadata store.
 *
 * Reads and writes session metadata.yaml files under the Hatfield
 * sessions directory, using HatfieldSessionStore::resolveSessionsBasePath()
 * as the single source of truth for path resolution.
 */
final class SessionMetadataStore
{
    private readonly string $sessionsBasePath;

    public function __construct(
        HatfieldSessionStore $hatfieldSessionStore,
    ) {
        $this->sessionsBasePath = $hatfieldSessionStore->resolveSessionsBasePath();
    }

    /**
     * Read session metadata from the YAML file.
     *
     * @return array<string, mixed> Empty array if the file does not exist
     */
    public function readSessionMetadata(string $sessionId): array
    {
        $path = $this->metadataPath($sessionId);

        if (!is_readable($path)) {
            return [];
        }

        $data = Yaml::parseFile($path);

        return \is_array($data) ? $data : [];
    }

    /**
     * Write session metadata, merging $fields into the existing file.
     *
     * Preserves all existing metadata keys; only overwrites those
     * present in $fields. Updates the updated_at timestamp.
     *
     * @param array<string, string> $fields Key-value pairs to set
     */
    public function writeSessionMetadata(string $sessionId, array $fields): void
    {
        $existing = $this->readSessionMetadata($sessionId);
        $merged = array_merge($existing, $fields);
        $merged['updated_at'] = date('c');

        $dir = \dirname($this->metadataPath($sessionId));
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $this->metadataPath($sessionId),
            Yaml::dump($merged, 4, 2),
        );
    }

    /**
     * Full path to a session's metadata.yaml file.
     */
    private function metadataPath(string $sessionId): string
    {
        return $this->sessionsBasePath.'/'.$sessionId.'/metadata.yaml';
    }
}
