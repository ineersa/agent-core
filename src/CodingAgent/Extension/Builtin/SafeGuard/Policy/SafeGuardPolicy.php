<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy;

/**
 * Immutable policy snapshot loaded from a SafeGuard policy file.
 *
 * Fields correspond 1:1 to Pi's SafeGuardPolicy interface.
 *
 * IMPORTANT: allowDestructiveInPaths is kept for serialization compatibility
 * but is NOT wired to any classification logic (mirrors Pi behavior where
 * the field is declared but never checked in classify.ts).
 */
final readonly class SafeGuardPolicy
{
    /**
     * @param list<string> $allowCommandPatterns       Command substrings that bypass destructive/dangerous checks
     * @param list<string> $allowWriteOutsideCwd        Absolute paths where writes outside CWD are always allowed
     * @param list<string> $allowDestructiveInPaths     Paths where destructive file ops are always allowed (compat — not wired)
     * @param list<string> $protectedReadPatterns       Filename/path patterns that require confirmation to read
     * @param list<string> $dangerousCommandPatterns    Extra command substrings to treat as dangerous (added to built-ins)
     */
    public function __construct(
        public array $allowCommandPatterns = [],
        public array $allowWriteOutsideCwd = [],
        public array $allowDestructiveInPaths = [],
        public array $protectedReadPatterns = [],
        public array $dangerousCommandPatterns = [],
    ) {
    }
}
