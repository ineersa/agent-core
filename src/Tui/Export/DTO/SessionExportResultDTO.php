<?php

declare(strict_types=1);

namespace Ineersa\Tui\Export\DTO;

use Ineersa\Tui\Export\SessionExportOutcomeEnum;

/**
 * Outcome of a session export. {@see $message} explains NoEvents and Failed
 * outcomes and is empty for Exported; callers build their own success labels
 * from {@see $path}.
 */
final readonly class SessionExportResultDTO
{
    public function __construct(
        public SessionExportOutcomeEnum $outcome,
        public string $path,
        public string $message = '',
    ) {
    }
}
