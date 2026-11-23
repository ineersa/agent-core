<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui;

enum TuiChildExecutionOutcomeEnum: string
{
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case Unknown = 'unknown';
}
