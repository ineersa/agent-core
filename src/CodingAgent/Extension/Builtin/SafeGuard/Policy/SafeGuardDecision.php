<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy;

/**
 * The result of classifying a tool call through SafeGuard.
 *
 * Returned by SafeGuardClassifier::classify(). Consumers (in SAFE-02
 * extension hook) use the kind to decide whether to block, prompt, or
 * allow the tool execution.
 */
final readonly class SafeGuardDecision
{
    public function __construct(
        public SafeGuardDecisionKind $kind,
        public string $reason,
        public string $toolName,
    ) {
    }

    /**
     * Convenience factory for an "allow" decision.
     */
    public static function allow(string $toolName): self
    {
        return new self(
            kind: SafeGuardDecisionKind::Allow,
            reason: 'Tool execution is safe.',
            toolName: $toolName,
        );
    }

    /**
     * Convenience factory for a blocking decision.
     */
    public static function block(
        SafeGuardDecisionKind $kind,
        string $reason,
        string $toolName,
    ): self {
        return new self(
            kind: $kind,
            reason: $reason,
            toolName: $toolName,
        );
    }

    /**
     * Whether this decision allows the tool to execute.
     */
    public function isAllowed(): bool
    {
        return SafeGuardDecisionKind::Allow === $this->kind;
    }
}
