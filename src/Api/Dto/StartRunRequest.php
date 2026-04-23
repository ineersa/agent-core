<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Api\Dto;

use Ineersa\AgentCore\Utils\StringUtils;
use Symfony\Component\Validator\Constraints as Assert;

final class StartRunRequest
{
    public function __construct(
        #[Assert\NotBlank(message: 'Field "prompt" must be a non-empty string.')]
        public ?string $prompt {
            set => StringUtils::normalizeNullable($value);
        },
        #[Assert\Valid]
        public StartRunMetadataRequest $metadata,
        public ?string $system_prompt = null {
            set => StringUtils::normalizeNullable($value);
        },
    ) {
    }
}
