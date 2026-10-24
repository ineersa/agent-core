<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\DTO;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated extensions:setup save payload (enablement + child membership + guided settings).
 *
 * Data-only; validators live in Constraint attributes / Validation namespace.
 * Serialized names match the TUI formState camelCase keys.
 */
final readonly class ExtensionsSetupFormDTO
{
    /**
     * @param array<string, mixed> $settings
     */
    public function __construct(
        #[Assert\NotBlank]
        public string $id,
        #[SerializedName('parentEnabled')]
        #[Assert\Type('bool')]
        public bool $parentEnabled,
        #[SerializedName('forksAlwaysOn')]
        #[Assert\Type('bool')]
        public bool $forksAlwaysOn,
        #[SerializedName('forksEnabled')]
        #[Assert\Type('bool')]
        public bool $forksEnabled,
        #[SerializedName('agentsAlwaysOn')]
        #[Assert\Type('bool')]
        public bool $agentsAlwaysOn,
        /** @var array<string, mixed> */
        #[Assert\Type('array')]
        public array $settings = [],
    ) {
    }
}
