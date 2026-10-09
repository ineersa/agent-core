<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Session;

final readonly class PendingTransitionWorkDTO
{
    /** @param array<string, mixed> $work */
    public function __construct(public array $work)
    {
    }
}
