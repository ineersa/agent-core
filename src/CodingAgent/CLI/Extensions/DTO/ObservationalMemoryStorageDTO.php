<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Nested storage block for guided OM settings.
 */
final readonly class ObservationalMemoryStorageDTO
{
    public function __construct(
        #[Assert\NotBlank]
        public string $database = '.hatfield/extensions-data/observational-memory/om.sqlite',
    ) {
    }
}
