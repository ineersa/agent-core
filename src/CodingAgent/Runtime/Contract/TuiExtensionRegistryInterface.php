<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Read-only registry of enabled project extensions that support TUI registration.
 *
 * @phpstan-return list<object> each element should implement registerTui(object): void
 */
interface TuiExtensionRegistryInterface
{
    /** @return list<object> */
    public function getTuiExtensions(): array;
}
