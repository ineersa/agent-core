<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * A user-originated command sent to an active run.
 *
 * @phpstan-type UserCommandType = 'message'|'steer'|'follow_up'|'append_message'|'cancel'|'answer_human'|'answer_tool_question'|'shell_command'|'rewind_to_turn'|'file_rewind_restore'|'file_rewind_undo'
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
