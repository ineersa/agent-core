<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Command;

/**
 * Defines the set of core command types recognized by the agent core system. This final class provides a static utility to validate whether a given command kind string belongs to the core domain. It serves as a strict type guard for command routing and processing.
 */
final class CoreCommandKind
{
    public const string Steer = 'steer';
    public const string FollowUp = 'follow_up';
    public const string Cancel = 'cancel';
    public const string HumanResponse = 'human_response';
    public const string Continue = 'continue';

    /** @var list<string> */
    public const array ALL = [
        self::Steer,
        self::FollowUp,
        self::Cancel,
        self::HumanResponse,
        self::Continue,
    ];

    public static function isCore(string $kind): bool
    {
        return \in_array($kind, self::ALL, true);
    }
}
