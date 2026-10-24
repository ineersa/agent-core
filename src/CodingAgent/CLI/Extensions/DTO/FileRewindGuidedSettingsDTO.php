<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\CLI\Extensions\DTO;

use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Guided File Rewind settings accepted by extensions:setup.
 */
final readonly class FileRewindGuidedSettingsDTO
{
    public function __construct(
        #[Assert\Type('bool')]
        public bool $enabled = true,
        #[SerializedName('max_retained_turns')]
        #[Assert\Positive]
        public int $maxRetainedTurns = 100,
        #[SerializedName('git_timeout_seconds')]
        #[Assert\Positive]
        public int $gitTimeoutSeconds = 30,
    ) {
    }
}
