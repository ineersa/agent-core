<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tool\CodeMode;

use Ineersa\CodingAgent\Agent\Tool\AgentResumeToolHandler;
use Ineersa\CodingAgent\Agent\Tool\ForkToolHandler;
use Ineersa\CodingAgent\Agent\Tool\SubagentToolHandler;
use Ineersa\CodingAgent\Tool\AskHumanTool;

/**
 * Nested tools that code_mode cannot complete through the direct toolbox bridge.
 *
 * These handlers start deferred child work or return interrupt payloads that the
 * outer agent loop must own. Reject by name before invocation so side effects
 * never start.
 *
 * @internal
 */
final class CodeModeUnsupportedTools
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return [
            SubagentToolHandler::NAME,
            ForkToolHandler::NAME,
            AgentResumeToolHandler::NAME,
            AskHumanTool::NAME,
        ];
    }

    public static function contains(string $name): bool
    {
        return \in_array($name, self::names(), true);
    }

    public static function rejectionMessage(string $name): string
    {
        return \sprintf(
            'Tool "%s" is not supported inside code_mode. Deferred child launches and interactive ask_human calls must run outside the script.',
            $name,
        );
    }
}
