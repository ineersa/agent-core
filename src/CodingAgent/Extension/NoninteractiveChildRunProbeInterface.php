<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension;

/**
 * Detects foreground non-interactive child subagent runs for extension hooks.
 *
 * Child workers inherit HATFIELD_APPROVAL_CHANNEL from the parent controller;
 * SafeGuard must not enter RequireApproval for those runs.
 *
 * @internal codingAgent-owned selection seam; not part of public ExtensionApi
 */
interface NoninteractiveChildRunProbeInterface
{
    public function isNoninteractiveChildRun(?string $runId): bool;
}
