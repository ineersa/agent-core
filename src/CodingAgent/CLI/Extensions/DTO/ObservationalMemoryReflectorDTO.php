<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\DTO;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Nested reflector block for guided OM settings.
 */
final readonly class ObservationalMemoryReflectorDTO
{
    public function __construct(
        #[SerializedName('reflect_after_observation_tokens')]
        #[Assert\Positive]
        public int $reflectAfterObservationTokens = 40000,
    ) {
    }
}
