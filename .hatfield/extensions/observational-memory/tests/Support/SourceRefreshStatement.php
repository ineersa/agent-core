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
            $this->sourceRead();
        }

        return $row;
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        // Incremental refresh materializes a change page through fetchAll(),
        // which does not call this statement's fetch() override. Fence both
        // paths after reading their snapshot and before any manifest write.
        if ([] !== $rows && str_starts_with($this->queryString, 'SELECT c.kind, c.source_id AS id,')) {
            $this->sourceRead();
        }

        return $rows;
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

    private function sourceRead(): void
    {
        ++self::$sourceReads;
        self::$afterSourceRead?->__invoke();
    }
}
