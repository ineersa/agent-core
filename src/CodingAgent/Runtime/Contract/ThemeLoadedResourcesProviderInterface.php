<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Narrow boundary for startup theme provenance used by {@see \Ineersa\CodingAgent\Runtime\LoadedResources\LoadedResourcesSummaryBuilder}.
 *
 * Implemented by {@see \Ineersa\Tui\Theme\ThemeRegistry}.
 */
interface ThemeLoadedResourcesProviderInterface
{
    /**
     * @return list<LoadedResourceItemDTO>
     */
    public function getLoadedThemeResourceItems(): array;

    /**
     * @return list<LoadedResourceConflictDTO>
     */
    public function getThemeResourceConflicts(): array;
}
