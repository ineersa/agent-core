<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Name collision surfaced in the loaded-resources startup block.
 */
final readonly class LoadedResourceConflictDTO
{
    public function __construct(
        public string $name,
        public string $winnerPath,
        public string $loserPath,
        public string $message = '',
    ) {
    }
}
