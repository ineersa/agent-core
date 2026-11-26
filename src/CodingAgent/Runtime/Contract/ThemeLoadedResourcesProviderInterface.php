<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\LoadedResources\LoadedResourcesSummaryBuilder;
use Ineersa\Tui\Theme\ThemeRegistry;

/**
 * Narrow boundary for startup theme provenance used by {@see LoadedResourcesSummaryBuilder}.
 *
 * Implemented by {@see ThemeRegistry}.
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
