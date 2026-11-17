<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Lifecycle;

use Ineersa\Hatfield\ExtensionApi\DTO\AfterSessionDeletedHookContextDTO;

/** Notification after permanent local deletion succeeds; failures cannot undo deletion. */
interface AfterSessionDeletedHookInterface
{
    public function onAfterSessionDeleted(AfterSessionDeletedHookContextDTO $context): void;
}
