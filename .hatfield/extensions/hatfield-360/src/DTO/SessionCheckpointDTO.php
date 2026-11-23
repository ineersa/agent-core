<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Hatfield360\DTO;

final readonly class SessionCheckpointDTO
{
    /** @param list<string> $capabilities */
    public function __construct(
        public string $sessionId,
        public int $epoch,
        public string $eventId,
        public string $provider,
        public array $capabilities,
    ) {
    }
}
