<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecision;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecisionKind;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;

/**
 * Main SafeGuard classifier — decides whether a tool call should be
 * allowed, blocked, or flagged for confirmation.
 *
 * Delegates to SafeGuardCommandMatcher for bash commands and
 * SafeGuardPathMatcher for file path checks.
 *
 * This is a pure domain layer — no tool execution, no hooks, no DI.
 */
final class SafeGuardClassifier
{
    public function __construct(
        private readonly SafeGuardCommandMatcher $commandMatcher = new SafeGuardCommandMatcher(),
        private readonly SafeGuardPathMatcher $pathMatcher = new SafeGuardPathMatcher(),
    ) {
    }

    /**
     * Classify a tool call against the active policy.
     *
     * @param string         $toolName  e.g., "bash", "write", "edit", "read"
     * @param array<string, mixed> $arguments Tool-specific decoded arguments
     * @param string         $cwd       Current working directory
     * @param SafeGuardPolicy $policy   Loaded policy for this invocation
     */
    public function classify(
        string $toolName,
        array $arguments,
        string $cwd,
        SafeGuardPolicy $policy,
    ): SafeGuardDecision {
        return match ($toolName) {
            'bash' => $this->classifyBash($arguments, $policy),
            'write', 'edit' => $this->classifyWrite($arguments, $cwd, $policy),
            'read' => $this->classifyRead($arguments, $cwd, $policy),
            default => SafeGuardDecision::allow($toolName),
        };
    }

    /**
     * Classify a bash tool call.
     *
     * Flow mirrors Pi's safe-guard.ts bash handler:
     *   1. Extract command string from arguments
     *   2. Check command allowlist first — if match, allow
     *   3. Run classification — if allow, allow
     *   4. HardBlock → block immediately
     *   5. All other kinds → flagged (future hook decides)
     *
     * @param array<string, mixed> $arguments
     */
    private function classifyBash(array $arguments, SafeGuardPolicy $policy): SafeGuardDecision
    {
        $command = (string) ($arguments['command'] ?? '');

        if ('' === $command) {
            return SafeGuardDecision::allow('bash');
        }

        // Check allowlist first
        if ($this->commandMatcher->isCommandAllowed($policy->allowCommandPatterns, $command)) {
            return SafeGuardDecision::allow('bash');
        }

        $classification = $this->commandMatcher->classify(
            command: $command,
            dangerousCommandPatterns: $policy->dangerousCommandPatterns,
        );

        if (SafeGuardDecisionKind::Allow === $classification->kind) {
            return SafeGuardDecision::allow('bash');
        }

        return SafeGuardDecision::block(
            kind: $classification->kind,
            reason: $classification->reason,
            toolName: 'bash',
        );
    }

    /**
     * Classify a write/edit tool call.
     *
     * Flow mirrors Pi's safe-guard.ts write/edit handler:
     *   1. Extract path, strip leading @
     *   2. If inside CWD → allow
     *   3. If in write path allowlist → allow
     *   4. Otherwise → flagged (WriteOutsideCwd)
     *
     * @param array<string, mixed> $arguments
     */
    private function classifyWrite(
        array $arguments,
        string $cwd,
        SafeGuardPolicy $policy,
    ): SafeGuardDecision {
        $rawPath = (string) ($arguments['path'] ?? '');

        // Strip leading @ (editor-style file references like @src/foo.php)
        if (\str_starts_with($rawPath, '@')) {
            $rawPath = \substr($rawPath, 1);
        }

        if ('' === $rawPath) {
            return SafeGuardDecision::allow('write');
        }

        // Inside CWD → always allowed
        if ($this->pathMatcher->isInsideCwd($cwd, $rawPath)) {
            return SafeGuardDecision::allow('write');
        }

        // Check path allowlist
        if ($this->pathMatcher->isPathInList($policy->allowWriteOutsideCwd, $rawPath)) {
            return SafeGuardDecision::allow('write');
        }

        return SafeGuardDecision::block(
            kind: SafeGuardDecisionKind::WriteOutsideCwd,
            reason: \sprintf('Write outside working directory: %s', $rawPath),
            toolName: 'write',
        );
    }

    /**
     * Classify a read tool call.
     *
     * Flow mirrors Pi's safe-guard.ts read handler:
     *   1. Extract path, strip leading @
     *   2. If not protected → allow
     *   3. Otherwise → flagged (ProtectedRead)
     *
     * @param array<string, mixed> $arguments
     */
    private function classifyRead(
        array $arguments,
        string $cwd,
        SafeGuardPolicy $policy,
    ): SafeGuardDecision {
        $rawPath = (string) ($arguments['path'] ?? '');

        // Strip leading @
        if (\str_starts_with($rawPath, '@')) {
            $rawPath = \substr($rawPath, 1);
        }

        if ('' === $rawPath) {
            return SafeGuardDecision::allow('read');
        }

        if (!$this->pathMatcher->isProtectedReadPath($policy, $rawPath)) {
            return SafeGuardDecision::allow('read');
        }

        return SafeGuardDecision::block(
            kind: SafeGuardDecisionKind::ProtectedRead,
            reason: \sprintf('Protected file — may contain secrets: %s', $rawPath),
            toolName: 'read',
        );
    }
}
