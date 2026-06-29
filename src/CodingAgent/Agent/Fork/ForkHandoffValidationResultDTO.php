<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

/**
 * Result of validating a fork child's handoff response.
 *
 * Indicates whether the handoff is structurally well-formed, lists
 * missing/present section headers, and provides a repair instruction
 * on failure.
 */
final readonly class ForkHandoffValidationResultDTO
{
    /**
     * @param bool         $valid             Whether the handoff is structurally valid
     * @param list<string> $missingSections   Section header names that are mandatory but missing
     * @param list<string> $presentSections   Section header names that were found
     * @param string|null  $repairInstruction Instructions for repairing the handoff (null on success)
     */
    public function __construct(
        public bool $valid,
        public array $missingSections = [],
        public array $presentSections = [],
        public ?string $repairInstruction = null,
    ) {
    }
}
