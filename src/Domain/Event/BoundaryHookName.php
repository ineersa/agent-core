<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Domain\Event;

final class BoundaryHookName
{
    public const string BEFORE_COMMAND_APPLY = 'before_command_apply';
    public const string AFTER_COMMAND_APPLY = 'after_command_apply';
    public const string BEFORE_TURN_DISPATCH = 'before_turn_dispatch';
    public const string AFTER_TURN_COMMIT = 'after_turn_commit';
    public const string BEFORE_RUN_FINALIZE = 'before_run_finalize';

    /** @var list<string> */
    public const array ALL = [
        self::BEFORE_COMMAND_APPLY,
        self::AFTER_COMMAND_APPLY,
        self::BEFORE_TURN_DISPATCH,
        self::AFTER_TURN_COMMIT,
        self::BEFORE_RUN_FINALIZE,
    ];

    public static function isBoundary(string $hookName): bool
    {
        return \in_array($hookName, self::ALL, true);
    }
}
