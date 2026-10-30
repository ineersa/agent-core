<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

final readonly class OutputReferenceDTO
{
    public function __construct(public string $path, public int $byteCount, public string $label = 'output')
    {
    }
}
