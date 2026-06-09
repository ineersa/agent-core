<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * In-memory reader for the file mention completion index.
 *
 * Loads a JSONL index file produced by {@see FileMentionIndexBuilder},
 * caches the parsed entries in memory, and reloads automatically when
 * the index file's mtime changes.
 *
 * Missing or unreadable index files are treated as empty — the caller
 * never receives an exception from index access.  This is intentional
 * local degradation: completion silently returns no suggestions until
 * the index is available.
 *
 * Invalid JSON lines within a valid index file are skipped with
 * a best-effort approach — the line is dropped and the remaining
 * entries are preserved.  If the file is entirely corrupt (e.g.
 * truncated during atomic rename failure), the previous in-memory
 * cache is retained.
 */
final class FileMentionIndexReader
{
    /** @var list<FileMentionIndexEntryDTO> */
    private array $entries = [];

    private int $loadedMtime = -1;

    private bool $loaded = false;

    /** @var array<string, list<FileMentionIndexEntryDTO>> */
    private array $childrenByDirectory = [];

    /** @var list<string> */
    private array $pathsLower = [];

    /** @var list<string> */
    private array $basenamesLower = [];

    public function __construct(
        private readonly string $indexPath,
    ) {
    }

    /**
     * All indexed entries, reloaded from disk if the index file
     * has changed since the last load.
     *
     * @return list<FileMentionIndexEntryDTO>
     */
    public function getEntries(): array
    {
        $this->ensureLoaded();

        return $this->entries;
    }

    /**
     * Direct children of a given directory path.
     *
     * @return list<FileMentionIndexEntryDTO>
     */
    public function getChildren(string $directory): array
    {
        $this->ensureLoaded();

        return $this->childrenByDirectory[$directory] ?? [];
    }

    /**
     * Flat list of lowercased paths for cheap substring/prefix
     * matching in providers.
     *
     * Index position matches {@see getEntries()}.
     *
     * @return list<string>
     */
    public function getPathsLower(): array
    {
        $this->ensureLoaded();

        return $this->pathsLower;
    }

    /**
     * Flat list of lowercased basenames for cheap matching.
     *
     * Index position matches {@see getEntries()}.
     *
     * @return list<string>
     */
    public function getBasenamesLower(): array
    {
        $this->ensureLoaded();

        return $this->basenamesLower;
    }

    /**
     * Whether the index has been loaded at least once (even if empty).
     */
    public function isLoaded(): bool
    {
        return $this->loaded;
    }

    /**
     * Unix timestamp of the currently loaded index file, or -1 if
     * never loaded.
     */
    public function loadedMtime(): int
    {
        return $this->loadedMtime;
    }

    // ─── Internal ──────────────────────────────────────────────────

    private function ensureLoaded(): void
    {
        $currentMtime = $this->indexMtime();

        if ($this->loaded && $currentMtime === $this->loadedMtime) {
            return;
        }

        $this->reload();
        $this->loadedMtime = $currentMtime;
        $this->loaded = true;
    }

    private function reload(): void
    {
        if (!is_file($this->indexPath) || !is_readable($this->indexPath)) {
            // Index missing or unreadable — keep previous data if any,
            // or start empty.
            if ($this->loadedMtime < 0) {
                $this->entries = [];
                $this->childrenByDirectory = [];
                $this->pathsLower = [];
                $this->basenamesLower = [];
            }

            return;
        }

        $lines = file($this->indexPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);

        if (false === $lines) {
            // Could not read file — keep previous in-memory state.
            return;
        }

        $entries = [];
        foreach ($lines as $line) {
            try {
                $decoded = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);

                if (!\is_array($decoded) || !isset($decoded['path']) || !\is_string($decoded['path'])) {
                    continue;
                }

                $entries[] = new FileMentionIndexEntryDTO(
                    path: $decoded['path'],
                    isDirectory: (bool) ($decoded['dir'] ?? false),
                );
            } catch (\JsonException) {
                // Invalid JSON line — skip, preserving surrounding entries.
                continue;
            }
        }

        $this->entries = $entries;

        // Build lookup structures
        $this->childrenByDirectory = [];
        $this->pathsLower = [];
        $this->basenamesLower = [];

        foreach ($entries as $entry) {
            $dir = \dirname($entry->path);
            if ('.' === $dir) {
                $dir = '';
            }
            $this->childrenByDirectory[$dir][] = $entry;

            $this->pathsLower[] = mb_strtolower($entry->path);
            $this->basenamesLower[] = mb_strtolower(basename($entry->path));
        }
    }

    private function indexMtime(): int
    {
        if (!is_file($this->indexPath)) {
            return -1;
        }

        $mtime = filemtime($this->indexPath);

        return false === $mtime ? -1 : $mtime;
    }
}
