<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * One selectable file-restore choice for /tree navigation.
 */
final readonly class TreeFileRestoreOption
{
    public function __construct(
        public string $id,
        public string $label,
        public bool $enabled,
        public ?string $disabledReason = null,
    ) {
    }
}
