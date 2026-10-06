<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

/** Storage-verified exact append, including zero-byte owner decisions. */
final readonly class VerifiedTransitionDTO
{
    /** @param array<string, mixed> $work
     * @param list<int> $eventSequences */
    public function __construct(public string $identity, public int $startOffset, public array $work, public array $eventSequences = [])
    {
    }
}
