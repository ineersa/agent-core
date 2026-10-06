<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Application\Message;

/** Parent-repair child maintenance captured before root source acceptance. */
final readonly class RepairDeferredChildrenDTO
{
    /**
     * @param list<RepairDeferredChildObligationDTO> $obligations
     */
    public function __construct(
        public string $runId,
        public string $commandId,
        public array $obligations,
    ) {
    }
}
