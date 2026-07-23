<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Read-only boundary for probing provider quota endpoints.
 *
 * TUI depends on this interface only — never on auth storage, API keys, or HTTP.
 * Absent providers produce an empty section list; configured failures degrade
 * into per-section errors without suppressing sibling sections.
 */
interface ProviderQuotaProbeServiceInterface
{
    public function probe(): ProviderQuotaReportDTO;
}
