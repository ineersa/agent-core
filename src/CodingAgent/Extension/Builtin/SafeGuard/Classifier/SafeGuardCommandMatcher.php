<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecision;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecisionKind;

/**
 * Faithful PHP port of Pi's classify.ts bash command classification.
 *
 * Returns SafeGuardDecision directly (BashClassification is eliminated).
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
    public function classify(string $command, array $dangerousCommandPatterns = []): SafeGuardDecision
    {
        // 1. Hard block: sudo — never allowlisted, never asked
        if (1 === preg_match(self::SUDO_PATTERN, $command)) {
            return $this->blockedCommand(
                SafeGuardDecisionKind::HardBlock,
                'sudo commands are not allowed',
                $command,
                [self::SUDO_PATTERN],
            );
        }

        // 2. Built-in destructive patterns
        if ($this->matchesAny($command, self::DESTRUCTIVE_PATTERNS)) {
            return $this->blockedCommand(
                SafeGuardDecisionKind::Destructive,
                'Destructive command',
                $command,
                self::DESTRUCTIVE_PATTERNS,
            );
        }

        // 3. Built-in dangerous git patterns
        if ($this->matchesAny($command, self::DANGEROUS_GIT_PATTERNS)) {
            return $this->blockedCommand(
                SafeGuardDecisionKind::DangerousGit,
                'Dangerous git operation',
                $command,
                self::DANGEROUS_GIT_PATTERNS,
            );
        }

        // 4. Sensitive info exposure (env, printenv)
        if ($this->matchesAny($command, self::SENSITIVE_INFO_PATTERNS)) {
            return $this->blockedCommand(
                SafeGuardDecisionKind::SensitiveInfo,
                'Exposes environment variables',
                $command,
                self::SENSITIVE_INFO_PATTERNS,
            );
        }

        // 5. User-defined dangerous patterns from policy
        $normalized = $this->normalizeCommand($command);
        foreach ($dangerousCommandPatterns as $pattern) {
            $normalizedPattern = $this->normalizeCommand($pattern);
            if (str_contains($normalized, $normalizedPattern)) {
                return $this->blockedCommand(
                    SafeGuardDecisionKind::CustomDangerous,
                    'Matched custom dangerous pattern',
                    $command,
                    $this->literalPattern($pattern),
                );
            }
        }

        return SafeGuardDecision::allow('');
    }

    /**
     * Check if a command matches any allowlist pattern.
     *
     * Mirrors Pi's isCommandAllowed() — substring match on normalized command.
     *
     * @param list<string> $allowCommandPatterns
     */
    public function isCommandAllowed(array $allowCommandPatterns, string $command): bool
    {
        $normalized = $this->normalizeCommand($command);

        foreach ($allowCommandPatterns as $pattern) {
            if (str_contains($normalized, $this->normalizeCommand($pattern))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $patterns
     */
    private function matchesAny(string $command, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (1 === preg_match($pattern, $command)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $patterns
     */
    private function blockedCommand(
        SafeGuardDecisionKind $kind,
        string $reason,
        string $command,
        array $patterns,
    ): SafeGuardDecision {
        return SafeGuardDecision::block(
            kind: $kind,
            reason: $reason,
            toolName: '',
            triggerInput: $command,
            matchSpans: $this->matchSpans($command, $patterns),
        );
    }

    /**
     * Return all regex matches, including repeats and matches from every rule
     * in the selected decision category. Offsets are byte offsets into the
     * exact command, which keeps slicing deterministic for UTF-8 input.
     *
     * @param list<string> $patterns
     *
     * @return list<array{start: int, length: int}>
     */
    private function matchSpans(string $command, array $patterns): array
    {
        $spans = [];

        foreach ($patterns as $pattern) {
            $matches = [];
            if (false === preg_match_all($pattern, $command, $matches, \PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[0] as [$match, $offset]) {
                if ('' !== $match) {
                    $spans[] = ['start' => $offset, 'length' => \strlen($match)];
                }
            }
        }

        usort($spans, static fn (array $left, array $right): int => [$left['start'], $left['length']] <=> [$right['start'], $right['length']]);

        return $spans;
    }

    /**
     * Convert the existing normalized custom-substring policy into a literal
     * case-insensitive regex solely to record its evidence in the original input.
     * Classification remains the established normalized substring check above.
     *
     * @return list<string>
     */
    private function literalPattern(string $pattern): array
    {
        $parts = preg_split('/\s+/u', trim($pattern), -1, \PREG_SPLIT_NO_EMPTY);
        if (false === $parts || [] === $parts) {
            return [];
        }

        return ['/'.implode('\\s+', array_map(static fn (string $part): string => preg_quote($part, '/'), $parts)).'/iu'];
    }

    /**
     * Normalize a command: lowercase, collapse whitespace.
     *
     * Mirrors Pi's normalize() helper.
     */
    private function normalizeCommand(string $command): string
    {
        return trim(mb_strtolower(preg_replace('/\s+/', ' ', $command) ?? $command));
    }
}
