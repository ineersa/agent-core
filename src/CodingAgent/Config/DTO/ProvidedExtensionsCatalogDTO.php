<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\DTO;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Root shape for config/hatfield-provided-extensions.yaml.
 */
final readonly class ProvidedExtensionsCatalogDTO
{
    /**
     * @param list<ProvidedExtensionDTO> $packages
     */
    public function __construct(
        /** @var list<ProvidedExtensionDTO> */
        #[Assert\Count(min: 1)]
        #[Assert\Valid]
        #[Assert\All([new Assert\Type(ProvidedExtensionDTO::class)])]
        public array $packages = [],
    ) {
    }
}
