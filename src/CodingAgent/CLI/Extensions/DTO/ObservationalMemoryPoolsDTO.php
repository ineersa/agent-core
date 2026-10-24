<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\DTO;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Nested pools block for guided OM settings.
 */
final readonly class ObservationalMemoryPoolsDTO
{
    public function __construct(
        #[SerializedName('observations_max_tokens')]
        #[Assert\Positive]
        public int $observationsMaxTokens = 30000,
    ) {
    }
}
