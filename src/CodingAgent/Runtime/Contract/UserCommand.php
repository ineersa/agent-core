<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * A user-originated command sent to an active run.
 */
final readonly class UserCommand
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public UserCommandTypeEnum $type,
        public ?string $text = null,
        public array $payload = [],
    ) {
    }
}
