<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Validated arguments for the hatfield_docs tool.
 */
final class HatfieldDocsArgumentsDTO
{
    public function __construct(
        #[Assert\NotBlank(message: 'The "operation" argument must be one of: list, read.')]
        #[Assert\Choice(choices: ['list', 'read'], message: 'The "operation" argument must be one of: list, read.')]
        public readonly string $operation = '',
        #[Assert\When(
            expression: 'this.operation === "read"',
            constraints: [
                new Assert\NotBlank(normalizer: 'trim', message: 'The "id" argument is required for read.'),
            ],
        )]
        public readonly ?string $id = null,
    ) {
    }
}
