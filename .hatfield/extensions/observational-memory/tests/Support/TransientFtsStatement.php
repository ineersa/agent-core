<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests\Support;

final class TransientFtsStatement extends \PDOStatement
{
    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        if (str_contains($this->queryString, ' MATCH ')) {
            $error = new \PDOException('database is locked');
            $error->errorInfo = ['HY000', 5, 'database is locked'];
            throw $error;
        }

        return parent::execute($params);
    }
}
