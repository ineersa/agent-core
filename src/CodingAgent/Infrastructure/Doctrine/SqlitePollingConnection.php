<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Infrastructure\Doctrine;

use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\Messenger\Bridge\Doctrine\Transport\Connection;

#[Exclude]
final class SqlitePollingConnection extends Connection
{
    /** @return array<array<string, mixed>>|null */
    public function get(int $fetchSize = 1): ?array
    {
        // Reuse Symfony's queue, delay and redelivery predicates without reserving
        // SQLite's writer slot for an empty poll. Never cache this advisory result.
        if (0 === $this->getMessageCount()) {
            return null;
        }

        // Another consumer may have claimed the messages since the read. The stock
        // receive path must recheck and claim inside our BEGIN IMMEDIATE transaction.
        return parent::get($fetchSize);
    }
}
