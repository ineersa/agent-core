<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

/**
 * Narrow read for session/run existence used by Extension API adapters.
 *
 * @internal
 */
interface SessionExistenceCheckerInterface
{
    public function exists(string $sessionId): bool;
}
