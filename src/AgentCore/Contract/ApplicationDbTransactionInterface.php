<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

/**
 * Short application-database transaction for local metadata writes.
 *
 * Implementations must not perform transport, model, or tool I/O.
 */
interface ApplicationDbTransactionInterface
{
    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function transactional(callable $callback): mixed;
}
