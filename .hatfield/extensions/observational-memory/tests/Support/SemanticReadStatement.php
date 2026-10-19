<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests\Support;

final class SemanticReadStatement extends \PDOStatement
{
    public static int $changedSources = 0;
    public static int $loadedSources = 0;

    protected function __construct()
    {
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $row = parent::fetch($mode, $cursorOrientation, $cursorOffset);
        if (\is_array($row) && \array_key_exists('content', $row)) {
            ++self::$changedSources;
        }
        if (false !== $row && str_contains($this->queryString, "json_extract(metadata, '$.source')")) {
            ++self::$loadedSources;
        }

        return $row;
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $rows = parent::fetchAll($mode, ...$args);
        foreach ($rows as $row) {
            if (\is_array($row) && \array_key_exists('content', $row)) {
                ++self::$changedSources;
            }
        }
        if (str_contains($this->queryString, "json_extract(metadata, '$.source')")) {
            self::$loadedSources += \count($rows);
        }

        return $rows;
    }
}
