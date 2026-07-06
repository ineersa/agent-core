<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Lifecycle;

final readonly class AfterTurnCommitEventSummaryDTO
{
    public function __construct(
        public int $seq,
        public string $type,
    ) {
    }
}
