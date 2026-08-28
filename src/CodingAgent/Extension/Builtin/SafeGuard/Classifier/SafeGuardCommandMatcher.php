<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Classifier;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecision;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy\SafeGuardDecisionKind;

use function Symfony\Component\String\u;

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
        if ([] !== $spans = $this->matchSpans($command, [self::SUDO_PATTERN])) {
            return SafeGuardDecision::block(
                kind: SafeGuardDecisionKind::HardBlock,
                reason: 'sudo commands are not allowed',
                toolName: '',
                triggerInput: $command,
                matchSpans: $spans,
            );
        }

        // 2. Built-in destructive patterns
        if ([] !== $spans = $this->matchSpans($command, self::DESTRUCTIVE_PATTERNS)) {
            return SafeGuardDecision::block(
                kind: SafeGuardDecisionKind::Destructive,
                reason: 'Destructive command',
                toolName: '',
                triggerInput: $command,
                matchSpans: $spans,
            );
        }

        // 3. Built-in dangerous git patterns
        if ([] !== $spans = $this->matchSpans($command, self::DANGEROUS_GIT_PATTERNS)) {
            return SafeGuardDecision::block(
                kind: SafeGuardDecisionKind::DangerousGit,
                reason: 'Dangerous git operation',
                toolName: '',
                triggerInput: $command,
                matchSpans: $spans,
            );
        }

        // 4. Sensitive info exposure (env, printenv)
        if ([] !== $spans = $this->matchSpans($command, self::SENSITIVE_INFO_PATTERNS)) {
            return SafeGuardDecision::block(
                kind: SafeGuardDecisionKind::SensitiveInfo,
                reason: 'Exposes environment variables',
                toolName: '',
                triggerInput: $command,
                matchSpans: $spans,
            );
        }

        // 5. User-defined dangerous patterns remain normalized substring rules,
        // not regex rules, so they carry the exact input without regex spans.
        $normalized = $this->normalizeCommand($command);
        foreach ($dangerousCommandPatterns as $pattern) {
            if (u($normalized)->containsAny($this->normalizeCommand($pattern))) {
                return SafeGuardDecision::block(
                    kind: SafeGuardDecisionKind::CustomDangerous,
                    reason: 'Matched custom dangerous pattern',
                    toolName: '',
                    triggerInput: $command,
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

        $merged = [];
        foreach ($spans as $span) {
            $last = array_key_last($merged);
            if (null === $last || $span['start'] > $merged[$last]['start'] + $merged[$last]['length']) {
                $merged[] = $span;
                continue;
            }

            $merged[$last]['length'] = max(
                $merged[$last]['length'],
                $span['start'] + $span['length'] - $merged[$last]['start'],
            );
        }

        return $merged;
    }

    /**
     * Normalize a command: lowercase, collapse whitespace.
     *
     * Mirrors Pi's normalize() helper.
     */
    private function normalizeCommand(string $command): string
    {
        return u($command)
            ->replaceMatches('/\s+/', ' ')
            ->trim()
            ->lower()
            ->toString();
    }
}
