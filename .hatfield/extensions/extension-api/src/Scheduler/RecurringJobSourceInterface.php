<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Scheduler;

interface RecurringJobSourceInterface
{
    /** @return iterable<RecurringJobDTO> */
    public function jobs(string $runId): iterable;
}
