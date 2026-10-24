<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\DTO;

use Ineersa\CodingAgent\CLI\Extensions\Validation\OpenUnitRatio;
use Symfony\Component\Serializer\Attribute\SerializedName;

/**
 * Nested observer block for guided OM settings.
 */
final readonly class ObservationalMemoryObserverDTO
{
    public function __construct(
        #[SerializedName('context_window_ratio')]
        #[OpenUnitRatio]
        public float $contextWindowRatio = 0.65,
    ) {
    }
}
