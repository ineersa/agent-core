<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Runtime;

use Ineersa\HatfieldExt\ObservationalMemory\DTO\LibrarianSettingsDTO;
use Symfony\Component\Config\Definition\Processor;

final class LibrarianSettingsProvider
{
    public static function fromArray(mixed $raw): ?LibrarianSettingsDTO
    {
        if (null === $raw) {
            return null;
        }
        $value = (new Processor())->processConfiguration(new LibrarianConfiguration(), [$raw]);

        return new LibrarianSettingsDTO($value['url'], $value['timeout_seconds']);
    }
}
