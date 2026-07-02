<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Command;

final readonly class FileRewindPreviewEntryDTO
{
    public function __construct(
        public string $path,
        public string $status,
        public int $addedLines,
        public int $removedLines,
        public bool $binary,
        public bool $tooLarge,
    ) {
    }
}
