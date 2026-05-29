<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi;

/**
 * Kinds of decisions a tool call hook can return.
 *
 * - Allow:         continue to the next hook or execute the tool handler.
 * - Block:         stop processing; return a structured error result.
 * - ReplaceResult: return a custom result without invoking the handler.
 *
 * @see ToolCallDecisionDTO
 */
enum ToolCallDecisionKindEnum: string
{
    case Allow = 'allow';
    case Block = 'block';
    case ReplaceResult = 'replace_result';
}
