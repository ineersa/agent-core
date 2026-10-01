<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Messenger;

/**
 * Clears abandoned Doctrine claims for the sole supervised run_control worker.
 */
interface RunControlClaimRecoveryInterface
{
    /**
     * @return array{released: int, failure: ?string}
     */
    public function releaseAbandonedClaims(string $sessionId): array;
}
