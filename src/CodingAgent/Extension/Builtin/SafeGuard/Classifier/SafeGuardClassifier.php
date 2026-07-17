<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecision;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecisionKind;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardPolicy;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardConfig;

/**
 * Main SafeGuard classifier — decides whether a tool call should be
 * allowed, blocked, or flagged for confirmation.
 *
 * Tool names are configurable from SafeGuardConfig (defaults: bash, write,
 * edit, read). This avoids hardcoded tool name strings.
 *
 * Delegates to SafeGuardCommandMatcher for bash commands and
 * SafeGuardPathMatcher for file path checks.
 *
 * This is a pure domain layer — no tool execution, no hooks, no DI.
 */
final class SafeGuardClassifier
{
    /**
     * @param string $bashToolName     Tool name that triggers bash classification (from config)
     * @param string $writeToolName    Tool name that triggers write classification (from config)
     * @param string $editToolName     Tool name that triggers write classification (from config)
     * @param string $readToolName     Tool name that triggers read classification (from config)
     * @param string $settingsToolName Tool name that triggers settings classification (from config)
     */
    public function __construct(
        private readonly string $bashToolName,
        private readonly string $writeToolName,
        private readonly string $editToolName,
        private readonly string $readToolName,
        private readonly string $settingsToolName = 'settings',
        private readonly SafeGuardCommandMatcher $commandMatcher = new SafeGuardCommandMatcher(),
        private readonly SafeGuardPathMatcher $pathMatcher = new SafeGuardPathMatcher(),
    ) {
    }

    /**
     * Build a classifier from SafeGuardConfig.
     */
    public static function fromConfig(SafeGuardConfig $config): self
    {
        return new self(
            bashToolName: $config->bashToolName,
            writeToolName: $config->writeToolName,
            editToolName: $config->editToolName,
            readToolName: $config->readToolName,
            settingsToolName: $config->settingsToolName,
        );
    }

    /**
     * Classify a tool call against the active policy.
     *
     * @param string               $toolName  e.g., "bash", "write", "edit", "read"
     * @param array<string, mixed> $arguments Tool-specific decoded arguments
     * @param string               $cwd       Current working directory
     * @param SafeGuardPolicy      $policy    Loaded policy for this invocation
     */
    public function classify(
        string $toolName,
        array $arguments,
        string $cwd,
        SafeGuardPolicy $policy,
    ): SafeGuardDecision {
        if ($toolName === $this->bashToolName) {
            return $this->classifyBash($arguments, $policy);
        }

        if ($toolName === $this->writeToolName || $toolName === $this->editToolName) {
            return $this->classifyWrite($arguments, $cwd, $policy);
        }

        if ($toolName === $this->readToolName) {
            return $this->classifyRead($arguments, $cwd, $policy);
        }

        if ($toolName === $this->settingsToolName) {
            return $this->classifySettings($arguments);
        }

        return SafeGuardDecision::allow($toolName);
    }

    /**
     * settings(read) is allowed; set/remove require confirmation.
     * Malformed ops have no side effect in SettingsTool validation, so Allow.
     *
     * @param array<string, mixed> $arguments
     */
    private function classifySettings(array $arguments): SafeGuardDecision
    {
        $operation = $arguments['operation'] ?? null;
        if (!\is_string($operation) || !\in_array($operation, ['set', 'remove'], true)) {
            return SafeGuardDecision::allow($this->settingsToolName);
        }

        $scope = \is_string($arguments['scope'] ?? null) ? $arguments['scope'] : '(missing)';
        $path = \is_string($arguments['path'] ?? null) ? $arguments['path'] : '(missing)';

        return SafeGuardDecision::block(
            kind: SafeGuardDecisionKind::CustomDangerous,
            reason: \sprintf('settings %s scope=%s path=%s', $operation, $scope, $path),
            toolName: $this->settingsToolName,
        );
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
            return SafeGuardDecision::allow($this->bashToolName);
        }

        // Check allowlist first
        if ($this->commandMatcher->isCommandAllowed($policy->allowCommandPatterns, $command)) {
            return SafeGuardDecision::allow($this->bashToolName);
        }

        $decision = $this->commandMatcher->classify(
            command: $command,
            dangerousCommandPatterns: $policy->dangerousCommandPatterns,
        );

        if ($decision->isAllowed()) {
            return SafeGuardDecision::allow($this->bashToolName);
        }

        // Carry forward the command matcher's decision, but ensure toolName is set
        return SafeGuardDecision::block(
            kind: $decision->kind,
            reason: $decision->reason,
            toolName: $this->bashToolName,
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
        if (str_starts_with($rawPath, '@')) {
            $rawPath = substr($rawPath, 1);
        }

        if ('' === $rawPath) {
            return SafeGuardDecision::allow($this->writeToolName);
        }

        // Inside CWD → always allowed
        if ($this->pathMatcher->isInsideCwd($cwd, $rawPath)) {
            return SafeGuardDecision::allow($this->writeToolName);
        }

        // Check path allowlist
        if ($this->pathMatcher->isPathInList($policy->allowWriteOutsideCwd, $rawPath)) {
            return SafeGuardDecision::allow($this->writeToolName);
        }

        return SafeGuardDecision::block(
            kind: SafeGuardDecisionKind::WriteOutsideCwd,
            reason: \sprintf('Write outside working directory: %s', $rawPath),
            toolName: $this->writeToolName,
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
        if (str_starts_with($rawPath, '@')) {
            $rawPath = substr($rawPath, 1);
        }

        if ('' === $rawPath) {
            return SafeGuardDecision::allow($this->readToolName);
        }

        if (!$this->pathMatcher->isProtectedReadPath($policy, $rawPath)) {
            return SafeGuardDecision::allow($this->readToolName);
        }

        return SafeGuardDecision::block(
            kind: SafeGuardDecisionKind::ProtectedRead,
            reason: \sprintf('Protected file — may contain secrets: %s', $rawPath),
            toolName: $this->readToolName,
        );
    }
}
