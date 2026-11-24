<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui\DTO;

use Ineersa\Hatfield\ExtensionApi\Tui\TuiRunObservationTypeEnum;

/** Content-free pending question or approval identity. */
final readonly class TuiForegroundRequestDTO
{
    /** $type is QuestionRequested or ApprovalRequested. */
    public function __construct(
        public string $requestId,
        public TuiRunObservationTypeEnum $type,
    ) {
    }
}
