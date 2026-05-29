<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy;

/**
 * Classification outcome for a tool call.
 *
 * Kinds correspond to Pi's BashClassification variants plus path-based checks:
 * - HardBlock: never negotiable (e.g., sudo)
 * - Destructive: destructive command (rm, rm -rf, etc.)
 * - DangerousGit: dangerous git operation (push --force, rebase, etc.)
 * - SensitiveInfo: command exposes env variables
 * - CustomDangerous: user-defined dangerous pattern match
 * - WriteOutsideCwd: write/edit targets a path outside the CWD
 * - ProtectedRead: read targets a path matching protected patterns
 * - Allow: tool is safe to execute
 */
enum SafeGuardDecisionKind: string
{
    case Allow = 'allow';
    case HardBlock = 'hard_block';
    case Destructive = 'destructive';
    case DangerousGit = 'dangerous_git';
    case SensitiveInfo = 'sensitive_info';
    case CustomDangerous = 'custom_dangerous';
    case WriteOutsideCwd = 'write_outside_cwd';
    case ProtectedRead = 'protected_read';
}
