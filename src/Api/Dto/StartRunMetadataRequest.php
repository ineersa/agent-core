<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Api\Dto;

use Ineersa\AgentCore\Utils\StringUtils;
use Symfony\Component\Validator\Constraints as Assert;

final class StartRunMetadataRequest
{
    /**
     * @param array<string, mixed>      $session
     * @param array<string, mixed>|null $tools_scope
     */
    public function __construct(
        #[Assert\NotBlank(message: 'Field "metadata.tenant_id" must be a non-empty string.')]
        public ?string $tenant_id {
            set => StringUtils::normalizeNullable($value);
        },
        #[Assert\NotBlank(message: 'Field "metadata.user_id" must be a non-empty string.')]
        public ?string $user_id {
            set => StringUtils::normalizeNullable($value);
        },
        public array $session = [],
        public ?string $model = null {
            set => StringUtils::normalizeNullable($value);
        },
        public ?array $tools_scope = null,
    ) {
    }
}
