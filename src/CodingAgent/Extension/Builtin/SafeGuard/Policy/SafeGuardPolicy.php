<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Extension\Builtin\SafeGuard\Policy;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardConfig;

/**
 * Immutable policy snapshot loaded from SafeGuardConfig.
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

    /**
     * Create a SafeGuardPolicy from SafeGuardConfig settings.
     *
     * protectedReadPatterns are always populated from the config's effective
     * list (which includes built-in defaults merged with YAML additions).
     */
    public static function fromConfig(SafeGuardConfig $config): self
    {
        return new self(
            allowCommandPatterns: $config->allowCommandPatterns,
            allowWriteOutsideCwd: $config->allowWriteOutsideCwd,
            allowDestructiveInPaths: $config->allowDestructiveInPaths,
            protectedReadPatterns: $config->protectedReadPatterns,
            dangerousCommandPatterns: $config->dangerousCommandPatterns,
        );
    }
}
