<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Command;

final class CoreCommandKind
{
    public const string Steer = 'steer';
    public const string FollowUp = 'follow_up';
    public const string AppendMessage = 'append_message';
    public const string Cancel = 'cancel';
    public const string HumanResponse = 'human_response';
    public const string Continue = 'continue';
    public const string Compact = 'compact';

    /** @var list<string> */
    public const array ALL = [
        self::Steer,
        self::FollowUp,
        self::AppendMessage,
        self::Cancel,
        self::HumanResponse,
        self::Continue,
        self::Compact,
    ];

    public static function isCore(string $kind): bool
    {
        return \in_array($kind, self::ALL, true);
    }
}
