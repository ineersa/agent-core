<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Lifecycle;

/**
 * Public summary of one already-committed canonical event from the hot batch.
 *
 * Optional payload/turnNo/createdAt carry that event's own provenance from the
 * committed RunEvent. Existing consumers that only read seq/type remain compatible.
 * createdAt is ISO-8601 when present.
 */
final readonly class AfterTurnCommitEventSummaryDTO
{
    /**
     * @param array<string, mixed> $payload
     * @param string|null          $createdAt ISO-8601 timestamp of the committed event, when available
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
