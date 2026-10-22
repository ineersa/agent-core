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
        if (\is_array($row)) {
            $this->afterSourceRead();
        }

        return $row;
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        if ([] !== $rows) {
            $this->afterSourceRead();
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

    private function afterSourceRead(): void
    {
        if (!str_starts_with($this->queryString, 'SELECT s.*, s.id AS canonical_id, NULL AS old_metadata FROM (')
            && !str_starts_with($this->queryString, 'SELECT c.kind, c.source_id AS id,')) {
            return;
        }

        ++self::$sourceReads;
        // Both the canonical stream and joined change page have acquired their
        // WAL read snapshot. Commit independently before the manifest write.
        self::$afterSourceRead?->__invoke();
    }
}
