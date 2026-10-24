<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\DTO;

use Ineersa\CodingAgent\CLI\Extensions\Validation\AbsolutePathList;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Guided SafeGuard settings accepted by extensions:setup.
 *
 * @param list<string> $allowCommandPatterns
 * @param list<string> $allowWriteOutsideCwd
 * @param list<string> $protectedReadPatterns
 * @param list<string> $dangerousCommandPatterns
 */
final readonly class SafeGuardGuidedSettingsDTO
{
    /**
     * @param list<string> $allowCommandPatterns
     * @param list<string> $allowWriteOutsideCwd
     * @param list<string> $protectedReadPatterns
     * @param list<string> $dangerousCommandPatterns
     */
    public function __construct(
        #[SerializedName('allow_command_patterns')]
        #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]
        public array $allowCommandPatterns = [],
        #[SerializedName('allow_write_outside_cwd')]
        #[AbsolutePathList]
        #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]
        public array $allowWriteOutsideCwd = [],
        #[SerializedName('protected_read_patterns')]
        #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]
        public array $protectedReadPatterns = [],
        #[SerializedName('dangerous_command_patterns')]
        #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]
        public array $dangerousCommandPatterns = [],
        #[SerializedName('auto_deny_in_noninteractive')]
        #[Assert\Type('bool')]
        public bool $autoDenyInNoninteractive = true,
    ) {
    }
}
