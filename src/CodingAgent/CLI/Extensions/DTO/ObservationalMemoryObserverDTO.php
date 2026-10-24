<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\DTO;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Nested observer block for guided OM settings.
 */
final readonly class ObservationalMemoryObserverDTO
{
    public function __construct(
        #[SerializedName('context_window_ratio')]
        #[Assert\GreaterThan(
            value: 0,
            message: 'context_window_ratio must satisfy 0 < ratio < 1.',
        )]
        #[Assert\LessThan(
            value: 1,
            message: 'context_window_ratio must satisfy 0 < ratio < 1.',
        )]
        public float $contextWindowRatio = 0.65,
    ) {
    }
}
