<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * One category section in the loaded-resources startup summary.
 */
final readonly class LoadedResourceSectionDTO
{
    /**
     * @param list<LoadedResourceItemDTO>     $items
     * @param list<LoadedResourceConflictDTO> $conflicts
     */
    public function __construct(
        public string $key,
        public string $label,
        public array $items,
        public array $conflicts = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return [] === $this->items && [] === $this->conflicts;
    }
}
