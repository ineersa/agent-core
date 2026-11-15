<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\DTO;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ExtensionComposerLockDTO
{
    /**
     * @param list<ExtensionComposerPackageDTO> $packages
     * @param list<ExtensionComposerPackageDTO> $packagesDev
     */
    public function __construct(
        #[Assert\Valid]
        public array $packages = [],
        #[Assert\Valid]
        #[SerializedName('packages-dev')]
        public array $packagesDev = [],
        public bool $dev = true,
    ) {
    }
}
