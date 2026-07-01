<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Read-only preflight for /tree file restore choices (sync, in-process or session-backed).
 */
interface TreeFileRestoreProviderInterface
{
    /**
     * @return list<TreeFileRestoreOption>
     */
    public function optionsForTurn(string $sessionId, int $targetTurnNo): array;
}
