<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Semantic;

/** Safe public failure. Never chain HTTP exceptions containing memory content. */
final class SemanticSearchException extends \RuntimeException
{
    public function __construct(public readonly string $failureCode, string $message)
    {
        parent::__construct($message);
    }
}
