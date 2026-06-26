<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Narrow boundary for building the display-only startup loaded-resources summary.
 *
 * Implemented by CodingAgent wiring (LoadedResourcesSummaryBuilder).
 * TUI listeners depend on this contract only — not on AppLoadedResources internals.
 */
interface LoadedResourcesSummaryProviderInterface
{
    public function build(): LoadedResourcesSummaryDTO;
}
