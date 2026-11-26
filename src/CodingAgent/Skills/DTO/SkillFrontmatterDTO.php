<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Skills\DTO;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class SkillFrontmatterDTO
{
    public function __construct(
        #[Assert\NotBlank(allowNull: true, normalizer: 'trim', message: 'Omit to use the skill directory name, or enter a non-empty name.')]
        public ?string $name = null,
        public ?string $description = null,
        #[SerializedName('disable-model-invocation')]
        public bool $disableModelInvocation = false,
    ) {
    }
}
