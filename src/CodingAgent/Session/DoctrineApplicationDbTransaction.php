<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Contract\ApplicationDbTransactionInterface;

/** DBAL-backed application metadata transaction for owner-local writes. */
final readonly class DoctrineApplicationDbTransaction implements ApplicationDbTransactionInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function transactional(callable $callback): mixed
    {
        $this->connection->beginTransaction();
        try {
            $result = $callback();
            $this->connection->commit();

            return $result;
        } catch (\Throwable $exception) {
            if ($this->connection->isTransactionActive()) {
                $this->connection->rollBack();
            }

            throw $exception;
        }
    }
}
