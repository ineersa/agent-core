<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;

/**
 * Provides bounded retained prompt pages and explicit canonical text lookup.
 */
interface HistoryProviderInterface
{
    /**
     * @return HistoryView one page, empty when no retained human prompts
     */
    public function forSession(string $runId, ?int $before = null, ?int $after = null): HistoryView;

    /** Exact text for explicit consumers of the published full-title contract. */
    public function promptText(string $runId, int $turnNo): string;
}
