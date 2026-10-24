<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Guided Observational Memory settings accepted by extensions:setup.
 */
final readonly class ObservationalMemoryGuidedSettingsDTO
{
    public function __construct(
        #[Assert\NotBlank(
            message: 'observational_memory.model must be an exact provider/model id (example: llama_cpp/flash).',
        )]
        #[Assert\Regex(
            // Match AiModelReference::parse first-slash split so local/Qwen/Qwen3 stays valid
            // while aliases such as @favorite remain rejected.
            pattern: '#^(?!@)[^/]+/.+$#',
            message: 'observational_memory.model must be an exact provider/model id (example: llama_cpp/flash).',
        )]
        public string $model = '',
        #[Assert\Valid]
        public ObservationalMemoryStorageDTO $storage = new ObservationalMemoryStorageDTO(),
        #[Assert\Valid]
        public ObservationalMemoryObserverDTO $observer = new ObservationalMemoryObserverDTO(),
        #[Assert\Valid]
        public ObservationalMemoryReflectorDTO $reflector = new ObservationalMemoryReflectorDTO(),
        #[Assert\Valid]
        public ObservationalMemoryPoolsDTO $pools = new ObservationalMemoryPoolsDTO(),
        #[Assert\Valid]
        public ObservationalMemorySemanticDTO $semantic = new ObservationalMemorySemanticDTO(),
    ) {
    }
}
