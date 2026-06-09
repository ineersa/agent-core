<?php

declare(strict_types=1);

namespace Ineersa\Tui\Completion;

/**
 * A single file or directory entry in the file mention index.
 *
 * Paths are stored normalised to forward slashes, relative to the
 * project CWD (the scan root).
 */
final readonly class FileMentionIndexEntryDTO
{
    public function __construct(
        public string $path,
        public bool $isDirectory,
    ) {
    }
}
