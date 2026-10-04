<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Coordination;

/** Storage-verified exact append, including zero-byte owner decisions. */
final readonly class VerifiedTransitionDTO
{
    /** @param array<string, mixed> $work */
    public function __construct(public string $identity, public int $startOffset, public int $endOffset, public array $work)
    {
    }
}
