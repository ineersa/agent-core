<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\CodingAgent\Agent\Fork\ForkSnapshotSummarizerInterface;
use Ineersa\CodingAgent\Compaction\CompactionPreparationDTO;

final class FakeForkSnapshotSummarizer implements ForkSnapshotSummarizerInterface
{
    public function __construct(
        private string $summaryText = 'Synthetic fork compaction summary for tests.',
        public int $calls = 0,
        public ?string $lastActiveSessionModel = null,
    ) {
    }

    public function summarize(CompactionPreparationDTO $preparation, ?string $activeSessionModel): string
    {
        ++$this->calls;
        $this->lastActiveSessionModel = $activeSessionModel;

        return $this->summaryText;
    }
}
