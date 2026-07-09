<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Edit;

/**
 * One edit hunk: optional stacked seek hints, old/new line slices, optional EOF anchor.
 *
 * @phpstan-type LineList list<string>
 */
final readonly class EditPatchChunkDTO
{
    /**
     * @param list<string> $seekHints stacked @@ seek hints (content after @@)
     * @param list<string> $oldLines  lines to remove (-)
     * @param list<string> $newLines  lines to insert (+)
     */
    public function __construct(
        public array $seekHints,
        public array $oldLines,
        public array $newLines,
        public bool $endOfFile = false,
    ) {
    }
}
