<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests\Support;

final class SourceRefreshStatement extends \PDOStatement
{
    public static ?\Closure $afterSourceRead = null;
    public static int $sourceReads = 0;
    /** @var list<int> */
    public static array $conflictCodes = [];

    protected function __construct()
    {
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        if (\is_array($row) && str_starts_with($this->queryString, 'SELECT s.* FROM (')) {
            ++self::$sourceReads;
            // The first connection has acquired its WAL read snapshot. Commit
            // on the second connection before the manifest upsert upgrades it.
            self::$afterSourceRead?->__invoke();
        }

        return $row;
    }

    public function execute(?array $params = null): bool
    {
        try {
            return parent::execute($params);
        } catch (\PDOException $error) {
            self::$conflictCodes[] = (int) ($error->errorInfo[1] ?? 0);
            throw $error;
        }
    }
}
