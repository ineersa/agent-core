<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tui\DTO;

/** Current foreground state at an accepted ready cut, never a completion event. */
final readonly class TuiForegroundBaselineDTO
{
    /** @param list<TuiForegroundRequestDTO> $pendingRequests */
    public function __construct(
        public string $sessionId,
        public int $canonicalSequence,
        public ?int $currentResponseOpeningSequence,
        public array $pendingRequests,
    ) {
    }
}
