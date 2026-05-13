<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * A user-originated command sent to an active run.
 *
 * @phpstan-type UserCommandType = 'message'|'steer'|'cancel'|'answer_human'
 */
final readonly class UserCommand
{
    /**
     * @param UserCommandType      $type
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $type,
        public ?string $text = null,
        public array $payload = [],
    ) {
    }
}
