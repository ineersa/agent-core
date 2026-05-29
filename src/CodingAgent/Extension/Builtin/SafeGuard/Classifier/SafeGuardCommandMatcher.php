<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecisionKind;

/**
 * Faithful PHP port of Pi's classify.ts bash command classification.
 *
 * Classification order (mirrors Pi):
 *   1. Hard block: sudo (never allowlisted)
 *   2. Built-in destructive
 *   3. Built-in dangerous git
 *   4. Sensitive info exposure
 *   5. User-defined custom dangerous patterns
 *   6. Allow
 */
final class SafeGuardCommandMatcher
{
    /**
     * Hard-block: sudo is never negotiable. Mirrors Pi's SUDO_RE.
     */
    private const SUDO_PATTERN = '/\bsudo\b/';

    /**
     * Destructive command patterns. Mirrors Pi's DESTRUCTIVE_RES.
     *
     * @var list<string>
     */
    private const DESTRUCTIVE_PATTERNS = [
        '/\brm\b/',
        '/\brmdir\b/',
        '/\bgit\s+clean\b/',
        '/\bgit\s+reset\b.*--hard/',
        '/\bgit\s+checkout\b.*--\s*\.\s*$/',
        '/\bmkfs\b/',
        '/\bdd\s+if=/',
        '/\bchmod\s+[0-7]{3,4}\b/',
        '/\bchown\s+-[rR]\b/',
        '/\bmv\b.*\/dev\/null/',
    ];

    /**
     * Dangerous git command patterns. Mirrors Pi's DANGEROUS_GIT_RES.
     *
     * @var list<string>
     */
    private const DANGEROUS_GIT_PATTERNS = [
        '/\bgit\s+push\b.*(-f\b|--force\b)/',
        '/\bgit\s+branch\s+-[dD]\b/',
        '/\bgit\s+tag\s+-d\b/',
        '/\bgit\s+rebase\b/',
        '/\bgit\s+reflog\s+expire/',
    ];

    /**
     * Sensitive info exposure patterns. Mirrors Pi's SENSITIVE_INFO_RES.
     *
     * @var list<string>
     */
    private const SENSITIVE_INFO_PATTERNS = [
        '/^\s*env\b/',
        '/^\s*printenv\b/',
        '/\benv\s*\|/',
        '/\bprintenv\s*\|/',
    ];

    /**
     * Classify a bash command. Mirrors Pi's classifyBash().
     *
     * @param list<string> $dangerousCommandPatterns User-defined dangerous substrings from policy
     */
    public function classify(string $command, array $dangerousCommandPatterns = []): BashClassification
    {
        // 1. Hard block: sudo — never allowlisted, never asked
        if (1 === \preg_match(self::SUDO_PATTERN, $command)) {
            return new BashClassification(
                kind: SafeGuardDecisionKind::HardBlock,
                reason: 'sudo commands are not allowed',
            );
        }

        // 2. Built-in destructive patterns
        foreach (self::DESTRUCTIVE_PATTERNS as $pattern) {
            if (1 === \preg_match($pattern, $command)) {
                return new BashClassification(
                    kind: SafeGuardDecisionKind::Destructive,
                    reason: 'Destructive command',
                );
            }
        }

        // 3. Built-in dangerous git patterns
        foreach (self::DANGEROUS_GIT_PATTERNS as $pattern) {
            if (1 === \preg_match($pattern, $command)) {
                return new BashClassification(
                    kind: SafeGuardDecisionKind::DangerousGit,
                    reason: 'Dangerous git operation',
                );
            }
        }

        // 4. Sensitive info exposure (env, printenv)
        foreach (self::SENSITIVE_INFO_PATTERNS as $pattern) {
            if (1 === \preg_match($pattern, $command)) {
                return new BashClassification(
                    kind: SafeGuardDecisionKind::SensitiveInfo,
                    reason: 'Exposes environment variables',
                );
            }
        }

        // 5. User-defined dangerous patterns from policy
        $normalized = $this->normalizeCommand($command);
        foreach ($dangerousCommandPatterns as $pattern) {
            $normalizedPattern = $this->normalizeCommand($pattern);
            if (\str_contains($normalized, $normalizedPattern)) {
                return new BashClassification(
                    kind: SafeGuardDecisionKind::CustomDangerous,
                    reason: 'Matched custom dangerous pattern',
                );
            }
        }

        return new BashClassification(
            kind: SafeGuardDecisionKind::Allow,
            reason: '',
        );
    }

    /**
     * Check if a command matches any allowlist pattern.
     *
     * Mirrors Pi's isCommandAllowed() — substring match on normalized command.
     */
    public function isCommandAllowed(array $allowCommandPatterns, string $command): bool
    {
        $normalized = $this->normalizeCommand($command);

        foreach ($allowCommandPatterns as $pattern) {
            if (\str_contains($normalized, $this->normalizeCommand($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize a command: lowercase, collapse whitespace.
     *
     * Mirrors Pi's normalize() helper.
     */
    private function normalizeCommand(string $command): string
    {
        return \trim(\mb_strtolower(\preg_replace('/\s+/', ' ', $command) ?? $command));
    }
}
