<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests\Support;

use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;

final class StatusFixtures
{
    public static function replace(JbcontextStatusStore $store, JbcontextSessionState $state): void
    {
        $store->update(static fn (): JbcontextSessionState => $state);
    }
}
