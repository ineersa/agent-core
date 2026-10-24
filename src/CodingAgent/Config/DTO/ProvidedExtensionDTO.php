<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Config\DTO;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * One curated Hatfield-provided extension from config/hatfield-provided-extensions.yaml.
 *
 * Built-in entries (SafeGuard) have no Composer package. Installable entries pin an
 * exact Packagist version for first-time installs only.
 */
final readonly class ProvidedExtensionDTO
{
    /**
     * @param list<ProvidedExtensionGuidedFieldDTO> $guidedFields
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $id,
        #[Assert\NotBlank]
        public string $name,
        public bool $builtin,
        #[SerializedName('extension_class')]
        #[Assert\NotBlank]
        public string $extensionClass,
        #[SerializedName('settings_key')]
        #[Assert\NotBlank]
        public string $settingsKey,
        /**
         * @var list<ProvidedExtensionGuidedFieldDTO>
         */
        #[SerializedName('guided_fields')]
        #[Assert\Valid]
        #[Assert\All([new Assert\Type(ProvidedExtensionGuidedFieldDTO::class)])]
        public array $guidedFields = [],
        #[Assert\When(
            expression: 'this.builtin === false',
            constraints: [new Assert\NotBlank()],
        )]
        #[Assert\When(
            expression: 'this.builtin === true',
            constraints: [new Assert\IsNull()],
        )]
        public ?string $package = null,
        #[Assert\When(
            expression: 'this.builtin === false',
            constraints: [new Assert\NotBlank()],
        )]
        #[Assert\When(
            expression: 'this.builtin === true',
            constraints: [new Assert\IsNull()],
        )]
        public ?string $version = null,
    ) {
    }
}
