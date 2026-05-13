<?php

declare(strict_types=1);

namespace App\Runtime\Contract;

/**
 * Request DTO for starting a new agent run.
 */
final readonly class StartRunRequest
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(
        public string $prompt,
        public string $cwd = '',
        public array $options = [],
    ) {
    }
}
