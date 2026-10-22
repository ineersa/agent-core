<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\DTO;

use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\Component\Validator\Constraints as Assert;

final readonly class NotifyParentArgumentsDTO
{
    public function __construct(
        #[Schema(description: 'Agent-authored UTF-8 text, nonblank and at most 8192 bytes.', minLength: 1, maxLength: 8192)]
        #[Assert\NotBlank(normalizer: 'trim')]
        #[Assert\Length(max: 8192, countUnit: Assert\Length::COUNT_BYTES)]
        #[Assert\Charset('UTF-8')]
        public string $text,
    ) {
    }
}
