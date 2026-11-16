<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\DTO;

final readonly class LibrarianSettingsDTO
{
    public function __construct(public string $url, public int $timeoutSeconds = 30)
    {
    }
}
