<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Probes configured AI-provider quotas for the `/usage` command.
 *
 * Implementations must never return secrets or raw response bodies.
 */
interface ProviderQuotaProbeServiceInterface
{
    public function probe(): ProviderQuotaReportDTO;
}
