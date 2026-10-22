<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests\Support;

final class SemanticReadStatement extends \PDOStatement
{
    public static int $changedSources = 0;
    public static int $loadedSources = 0;
    /** @var list<string> */
    public static array $chunkLookups = [];
    /** @var list<string> */
    public static array $sourceReads = [];
    private bool $sourceReadRecorded = false;

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        $this->sourceReadRecorded = false;
        if (str_starts_with($this->queryString, 'SELECT d.id FROM om_semantic_document s JOIN json_each')) {
            self::$chunkLookups[] = $this->queryString;
        }

        return parent::execute($params);
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        $this->recordSourceRead();
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
        $this->recordSourceRead();
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

    private function recordSourceRead(): void
    {
        if (!$this->sourceReadRecorded && str_starts_with($this->queryString, 'SELECT ')
            && (str_contains($this->queryString, 'om_observation') || str_contains($this->queryString, 'om_reflection'))) {
            self::$sourceReads[] = $this->queryString;
            $this->sourceReadRecorded = true;
        }
    }
}
