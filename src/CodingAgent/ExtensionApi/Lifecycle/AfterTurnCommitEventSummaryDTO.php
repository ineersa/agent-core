<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Lifecycle;

/**
 * Public summary of one already-committed canonical event from the hot batch.
 *
 * Optional payload/turnNo/createdAt are filled from the commit context when
 * available. Existing consumers that only read seq/type remain compatible.
 */
final readonly class AfterTurnCommitEventSummaryDTO
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int $seq,
        public string $type,
        public array $payload = [],
        public ?int $turnNo = null,
        public ?string $createdAt = null,
    ) {
    }
}
