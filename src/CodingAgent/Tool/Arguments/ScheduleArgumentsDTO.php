<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\Arguments;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class ScheduleArgumentsDTO
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        #[Assert\Choice(choices: ['create', 'list', 'cancel'])]
        public string $action,
        #[Assert\Choice(choices: ['bash', 'code_mode'])]
        #[Assert\When(expression: 'this.action === "create"', constraints: [new Assert\NotBlank()])]
        public ?string $tool = null,
        #[Schema(description: 'Existing Bash or code_mode arguments. backgrounded=true is not supported: scheduled work is already asynchronous.')]
        public array $arguments = [],
        #[Schema(description: 'Symfony interval: seconds as a string, ISO 8601 duration, or relative interval such as "5 minutes".')]
        #[Assert\When(expression: 'this.action === "create"', constraints: [new Assert\NotBlank()])]
        public ?string $every = null,
        #[Schema(description: 'Optional recurring lifetime in seconds. Omit to run until cancelled or Hatfield closes.')]
        #[Assert\Positive]
        public ?int $for_seconds = null,
        #[Assert\When(expression: 'this.action === "cancel"', constraints: [new Assert\NotBlank()])]
        public ?string $id = null,
    ) {
    }
}
