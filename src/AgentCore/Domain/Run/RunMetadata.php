<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Run;

final readonly class RunMetadata
{
    /**
     * @param array<string, mixed>      $session
     * @param array<string, mixed>|null $toolsScope
     * @param list<string>|null         $extensions Effective child-run extension allowlist (class names)
     */
    public function __construct(
        public array $session = [],
        public ?string $model = null,
        public ?string $reasoning = null,
        public ?array $toolsScope = null,
        public ?int $contextWindow = null,
        public ?array $extensions = null,
    ) {
    }
}
