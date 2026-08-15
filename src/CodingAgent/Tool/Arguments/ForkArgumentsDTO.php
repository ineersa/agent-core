<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Ineersa\CodingAgent\Config\ModelResolver;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated arguments for the fork tool.
 */
final class ForkArgumentsDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'fork requires a non-empty task string.')]
        public readonly string $task = '',
        #[Assert\When(
            expression: 'this.model !== null',
            constraints: [new Assert\NotBlank(message: 'fork model must be a non-empty string when provided.')],
        )]
        public readonly ?string $model = null,
        #[Assert\Choice(choices: ModelResolver::LEVELS, message: 'fork thinking must be one of: {{ choices }}.')]
        public readonly ?string $thinking = null,
    ) {
    }
}
