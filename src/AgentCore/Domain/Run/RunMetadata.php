<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

use Symfony\Component\Serializer\Attribute\SerializedName;

final readonly class RunMetadata
{
    /**
     * @param array<string, mixed>      $session
     * @param array<string, mixed>|null $toolsScope
     */
    public function __construct(
        public array $session = [],
        public ?string $model = null,
        #[SerializedName('tools_scope')]
        public ?array $toolsScope = null,
    ) {
    }
}
