<?php

declare(strict_types=1);

namespace Ineersa\Tui\Export;

enum SessionExportOutcomeEnum: string
{
    case Exported = 'exported';
    case NoEvents = 'no_events';
    case Failed = 'failed';
}
