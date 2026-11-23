<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui\DTO;

use Ineersa\Hatfield\ExtensionApi\Tui\TuiChildExecutionOutcomeEnum;
use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObservationTypeEnum;

final readonly class TuiRunObservationDTO
{
    public function __construct(
        public string $sessionId,
        public int $sequence,
        public TuiRunObservationTypeEnum $type,
        public ?string $requestId = null,
        public ?string $childRunId = null,
        public ?string $parentRunId = null,
        public ?TuiChildExecutionOutcomeEnum $childOutcome = null,
    ) {
    }
}
