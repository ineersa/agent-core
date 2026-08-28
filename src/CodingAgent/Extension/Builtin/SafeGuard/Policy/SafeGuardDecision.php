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
        public ?string $triggerInput = null,
        /** @var list<array{start: int, length: int}> */
        public array $matchSpans = [],
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
     *
     * @param list<array{start: int, length: int}> $matchSpans
     */
    public static function block(
        SafeGuardDecisionKind $kind,
        string $reason,
        string $toolName,
        ?string $triggerInput = null,
        array $matchSpans = [],
    ): self {
        return new self(
            kind: $kind,
            reason: $reason,
            toolName: $toolName,
            triggerInput: $triggerInput,
            matchSpans: $matchSpans,
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
