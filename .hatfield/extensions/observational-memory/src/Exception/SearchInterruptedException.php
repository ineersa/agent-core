<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Exception;

final class SearchInterruptedException extends \RuntimeException
{
    /** @param array<string, mixed> $result */
    public function __construct(public readonly array $result)
    {
        parent::__construct('Memory retrieval interrupted.');
    }
}
