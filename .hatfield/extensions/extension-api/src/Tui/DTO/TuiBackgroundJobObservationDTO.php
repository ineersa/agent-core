<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui\DTO;

use Ineersa\Hatfield\ExtensionApi\Tui\TuiBackgroundJobObservationTypeEnum;

final readonly class TuiBackgroundJobObservationDTO
{
    public function __construct(public string $sessionId, public string $jobId, public TuiBackgroundJobObservationTypeEnum $type)
    {
    }
}
