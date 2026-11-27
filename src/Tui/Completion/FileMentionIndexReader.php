<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

use Ineersa\CodingAgent\CLI\CompletionFileIndexRefreshCommand;
use Ineersa\CodingAgent\CLI\DTO\FileMentionIndexEntryDTO;
use Ineersa\CodingAgent\CLI\FileMentionIndexBuilder;

/**
 * In-memory reader for the file mention completion index.
 *
 * Loads a JSONL index file produced by
 * {@see FileMentionIndexBuilder},
 * caches the parsed entries in memory, and reloads automatically when
 * the index file's inode, mtime, or size changes.  The inode normally
 * changes because the builder replaces the file by rename, which catches
 * rewrites within the same mtime second; mtime and size are the fallback
 * signal.
 *
 * Missing or unreadable index files are treated as empty — the caller
 * never receives an exception from index access.  This is intentional
 * local degradation: completion silently returns no suggestions until
 * {@see CompletionFileIndexRefreshCommand}
 * (scheduler) produces an index.  When the index file exists and can be
 * read but every line within it is invalid JSON, the loaded index becomes
 * empty (no previous cache is retained).  The previous in-memory
 * entries are preserved only when the file cannot be read at all
 * (missing, unreadable, or file() returns false).
 *
 * Invalid JSON lines within a valid index file are skipped with
 * a best-effort approach — the line is dropped and the remaining
 * entries are preserved.
 */
final class FileMentionIndexReader
{
    /** @var list<FileMentionIndexEntryDTO> */
    private array $entries = [];

    /** @var array{int, int, int}|null inode, mtime, size of the last loaded file; null when absent */
    private ?array $loadedStat = null;

    private bool $loaded = false;

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
     * Flat list of lowercased paths for cheap substring/prefix
     * matching in providers.
     *
     * Index position matches the last {@see getEntries()} result; call that first.
     *
     * @return list<string>
     */
    public function getPathsLower(): array
    {
        // No refresh check here: a rebuild landing between the getters of one
        // completion request would otherwise return arrays from two different
        // index versions. getEntries() is the only call that picks up a refresh.
        if (!$this->loaded) {
            $this->ensureLoaded();
        }

        return $this->pathsLower;
    }

    /**
     * Flat list of lowercased basenames for cheap matching.
     *
     * Index position matches the last {@see getEntries()} result; call that first.
     *
     * @return list<string>
     */
    public function getBasenamesLower(): array
    {
        // No refresh check here: a rebuild landing between the getters of one
        // completion request would otherwise return arrays from two different
        // index versions. getEntries() is the only call that picks up a refresh.
        if (!$this->loaded) {
            $this->ensureLoaded();
        }

        return $this->basenamesLower;
    }

    // ─── Internal ──────────────────────────────────────────────────

    private function ensureLoaded(): void
    {
        $currentStat = $this->indexStat();

        if ($this->loaded && $currentStat === $this->loadedStat) {
            return;
        }

        $this->reload();
        $this->loadedStat = $currentStat;
        $this->loaded = true;
    }

    private function reload(): void
    {
        if (!is_file($this->indexPath) || !is_readable($this->indexPath)) {
            // Index missing or unreadable — keep previous data if any,
            // or start empty.
            if (null === $this->loadedStat) {
                $this->entries = [];
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
        $this->pathsLower = [];
        $this->basenamesLower = [];

        foreach ($entries as $entry) {
            $this->pathsLower[] = mb_strtolower($entry->path);
            $this->basenamesLower[] = mb_strtolower(basename($entry->path));
        }
    }

    /**
     * @return array{int, int, int}|null
     */
    private function indexStat(): ?array
    {
        // A long-running TUI would otherwise keep serving PHP's cached
        // stat result for the index path and miss refreshes.
        clearstatcache(true, $this->indexPath);

        if (!is_file($this->indexPath)) {
            return null;
        }

        $stat = stat($this->indexPath);

        return false === $stat ? null : [$stat['ino'], $stat['mtime'], $stat['size']];
    }
}
