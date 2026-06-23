<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

/**
 * Kinds of decisions a tool result hook can return.
 *
 * - Keep:    retain the current tool result as-is.
 * - Replace: replace the tool result with supplied values.
 *
 * @see ToolResultDecisionDTO
 */
enum ToolResultDecisionKindEnum: string
{
    case Keep = 'keep';
    case Replace = 'replace';
}
