<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\DTO;

use Ineersa\CodingAgent\CLI\Extensions\Validation\ExactProviderModelId;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Guided Observational Memory settings accepted by extensions:setup.
 */
final readonly class ObservationalMemoryGuidedSettingsDTO
{
    public function __construct(
        #[ExactProviderModelId]
        public string $model = '',
        #[Assert\Valid]
        public ObservationalMemoryStorageDTO $storage = new ObservationalMemoryStorageDTO(),
        #[Assert\Valid]
        public ObservationalMemoryObserverDTO $observer = new ObservationalMemoryObserverDTO(),
        #[Assert\Valid]
        public ObservationalMemoryReflectorDTO $reflector = new ObservationalMemoryReflectorDTO(),
        #[Assert\Valid]
        public ObservationalMemoryPoolsDTO $pools = new ObservationalMemoryPoolsDTO(),
    ) {
    }
}
