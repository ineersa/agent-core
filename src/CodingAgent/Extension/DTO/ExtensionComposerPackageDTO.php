<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\DTO;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class ExtensionComposerPackageDTO
{
    /**
     * @param array<string, mixed>|null $source
     * @param array<string, mixed>|null $dist
     */
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Regex('/^[a-z0-9_.-]+\/[a-z0-9_.-]+$/D')]
        public string $name,
        #[Assert\NotBlank]
        public string $version,
        public ?array $source = null,
        public ?array $dist = null,
    ) {
    }
}
