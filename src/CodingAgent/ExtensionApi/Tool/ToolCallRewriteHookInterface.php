<?php

declare(strict_types=1);

namespace Ineersa\Hatfield\ExtensionApi\Tool;

/**
 * Hook that rewrites tool-call arguments before the policy hook chain runs.
 *
 * Rewrite hooks run BEFORE ToolCallRequested is dispatched (and therefore
 * BEFORE SafeGuard and other policy hooks see the arguments). The tool
 * handler receives the rewritten arguments.
 *
 * Rewrite is a separate concern from policy decisions (Allow/Block/
 * RequireApproval/ReplaceResult). Rewriters transform; policy hooks decide.
 *
 * Multiple rewrite hooks compose left-to-right: each receives the
 * (possibly already rewritten) arguments via the context.
 *
 * @see ToolCallContextDTO
 * @see ToolCallRewriteHookProviderInterface
 */
interface ToolCallRewriteHookInterface
{
    /**
     * Rewrite tool call arguments.
     *
     * @param ToolCallContextDTO $context Current tool call context with (possibly already rewritten) arguments
     *
     * @return array<string, mixed>|null Rewritten arguments, or null to leave unchanged
     */
    public function rewriteArguments(ToolCallContextDTO $context): ?array;
}
