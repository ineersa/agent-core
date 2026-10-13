<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract;

/** Binds event ordinals to allocated staged sequences before intent publication. */
interface CanonicalSequenceBoundActionInterface
{
    /** @param list<int> $sequences */
    public function bindCanonicalSequences(array $sequences): object;
}
