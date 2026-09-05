<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\State;

enum JbcontextSessionModeEnum: string
{
    case Pending = 'pending';
    case Eligible = 'eligible';
    case Disabled = 'disabled';
}
