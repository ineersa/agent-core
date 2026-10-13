<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message;

/** One captured child-maintenance step for a parent repair intent. */
final readonly class RepairDeferredChildObligationDTO
{
    public const KIND_INTERRUPT = 'interrupt';

    public const KIND_CANCEL = 'cancel';

    public const KIND_SETTLE = 'settle';

    public function __construct(
        public string $kind,
        public string $lifecycleId,
        public int $parentTurnNo,
        public ?string $childRunId = null,
    ) {
    }
}
