<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Command;

final class CoreCommandKind
{
    /** @var list<string> */
    public const array ALL = ['steer', 'follow_up', 'cancel', 'human_response', 'continue'];

    public static function isCore(string $kind): bool
    {
        return \in_array($kind, self::ALL, true);
    }
}
