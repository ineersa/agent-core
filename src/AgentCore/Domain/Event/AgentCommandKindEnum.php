<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

/** Command kinds interpreted by canonical event replay. */
enum AgentCommandKindEnum: string
{
    case Steer = 'steer';
    case FollowUp = 'follow_up';
    case AppendMessage = 'append_message';
    case Compact = 'compact';
    case Cancel = 'cancel';
    case ShellCommand = 'shell_command';

    public static function fromPayload(mixed $kind): ?self
    {
        return \is_string($kind) ? self::tryFrom($kind) : null;
    }

    public function appendsMessage(): bool
    {
        return match ($this) {
            self::Steer, self::FollowUp, self::AppendMessage => true,
            default => false,
        };
    }
}
