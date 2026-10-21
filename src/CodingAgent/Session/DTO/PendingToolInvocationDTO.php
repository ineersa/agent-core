<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session\DTO;

/** Scalar canonical invocation identity, not a claim or an execution receipt. */
final readonly class PendingToolInvocationDTO
{
    public function __construct(
        public int $turnNo,
        public string $stepId,
        public int $attempt,
        public bool $shell,
        public bool $standalone,
    ) {
    }
}
