<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Extension load outcome for startup display.
 */
final readonly class LoadedExtensionItemDTO
{
    public function __construct(
        public string $className,
        public bool $loaded,
        public string $errorMessage = '',
    ) {
    }
}
