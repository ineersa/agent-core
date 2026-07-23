<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\ProviderQuota;

use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaProbeServiceInterface;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaReportDTO;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaSectionDTO;

/**
 * Deterministic provider-quota stub for APP_ENV=test.
 */
final class FakeProviderQuotaProbeService implements ProviderQuotaProbeServiceInterface
{
    public function probe(): ProviderQuotaReportDTO
    {
        return new ProviderQuotaReportDTO([
            new ProviderQuotaSectionDTO('OpenAI Codex', [
                '- Codex (5h): 83% left, resets in 2h',
                '- Plan: pro',
                '- Account: user@example.com',
            ]),
            new ProviderQuotaSectionDTO('z.ai', [
                '- Tokens (250/1,000): 75% left, resets in 1h',
            ]),
        ]);
    }
}
