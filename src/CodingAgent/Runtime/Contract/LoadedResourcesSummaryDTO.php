<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Display-only aggregate of resources loaded for the current TUI process.
 *
 * Built in CodingAgent; consumed by TUI renderer only. Must not feed LLM context.
 */
final readonly class LoadedResourcesSummaryDTO
{
    /**
     * @param list<LoadedResourceSectionDTO> $sections ordered display sections
     */
    public function __construct(
        public array $sections,
    ) {
    }

    /**
     * @return list<LoadedResourceSectionDTO>
     */
    public function nonEmptySections(): array
    {
        return array_values(array_filter(
            $this->sections,
            static fn (LoadedResourceSectionDTO $section): bool => !$section->isEmpty(),
        ));
    }
}
