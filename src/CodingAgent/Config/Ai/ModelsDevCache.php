<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\Ai;

/**
 * Reads filtered models.dev JSON from user cache or bundled snapshot.
 *
 * Network I/O lives only in providers:update — this class never fetches.
 * Constructed manually (not a container service) by AppConfigLoader / providers:update.
 */
final class ModelsDevCache
{
    public const CACHE_RELATIVE_PATH = '.hatfield/cache/models-dev.json';
    public const ETAG_RELATIVE_PATH = '.hatfield/cache/models-dev.etag';

    public function __construct(
        private readonly string $homeDir,
        private readonly string $snapshotPath,
    ) {
    }

    /**
     * Prefer fresh user cache; fall back to committed snapshot. Empty when both absent/unreadable.
     *
     * @return array<string, mixed>
     */
    public function loadFilteredProviders(): array
    {
        $fromCache = $this->decodeFile($this->cachePath());
        if ([] !== $fromCache) {
            return $fromCache;
        }

        return $this->decodeFile($this->snapshotPath);
    }

    public function cachePath(): string
    {
        return rtrim($this->homeDir, '/').'/'.self::CACHE_RELATIVE_PATH;
    }

    public function etagPath(): string
    {
        return rtrim($this->homeDir, '/').'/'.self::ETAG_RELATIVE_PATH;
    }

    public function snapshotPath(): string
    {
        return $this->snapshotPath;
    }

    public function readStoredEtag(): ?string
    {
        $path = $this->etagPath();
        if (!is_readable($path)) {
            return null;
        }
        $etag = file_get_contents($path);
        if (false === $etag) {
            return null;
        }
        $etag = trim($etag);

        return '' === $etag ? null : $etag;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeFile(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }
        $raw = file_get_contents($path);
        if (false === $raw || '' === trim($raw)) {
            return [];
        }
        try {
            $decoded = json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }
}
