<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Agent\Fork;

/**
 * Validates a fork child's handoff response for structural completeness.
 *
 * Checks that all mandatory section headers are present and that the
 * handoff is not a conversational reply.
 *
 * Required (mandatory) section headers:
 *   - ## 1. Result / status
 *   - ## 5. Changes made
 *   - ## 11. Final handoff
 *
 * Detection is header-based and tolerant of leading whitespace/case
 * for the leading "##" and the section number, but matches the exact
 * section title text.
 *
 * In section 1, the handoff must contain a filesystem-changes statement
 * (either "files changed" or "No filesystem changes made").
 */
final readonly class ForkHandoffValidator
{
    /**
     * Mandatory section header patterns.
     *
     * Each entry maps a readable header name to a regex pattern that
     * matches the header line in the handoff.
     *
     * @var array<string, string>
     */
    private const MANDATORY_SECTIONS = [
        '## 1. Result / status' => '/^[#\s]*#\s*1\.\s*Result\s*\/\s*status\s*$/im',
        '## 5. Changes made' => '/^[#\s]*#\s*5\.\s*Changes\s+made\s*$/im',
        '## 11. Final handoff' => '/^[#\s]*#\s*11\.\s*Final\s+handoff\s*$/im',
    ];

    /**
     * Regex patterns for detecting section headers.
     *
     * Used to extract all present section headers from the handoff.
     *
     * @var array<string, string>
     */
    private const ALL_SECTION_PATTERNS = [
        '## 1. Result / status' => '/^[#\s]*#\s*1\.\s*Result\s*\/\s*status\s*$/im',
        '## 2. Scope and authority' => '/^[#\s]*#\s*2\.\s*Scope\s+and\s+authority\s*$/im',
        '## 3. Navigation / tool trail' => '/^[#\s]*#\s*3\.\s*Navigation\s*\/\s*tool\s+trail\s*$/im',
        '## 4. Evidence and context discovered' => '/^[#\s]*#\s*4\.\s*Evidence\s+and\s+context\s+discovered\s*$/im',
        '## 5. Changes made' => '/^[#\s]*#\s*5\.\s*Changes\s+made\s*$/im',
        '## 6. Data/control flow' => '/^[#\s]*#\s*6\.\s*Data\/control\s+flow\s*$/im',
        '## 7. Validation performed' => '/^[#\s]*#\s*7\.\s*Validation\s+performed\s*$/im',
        '## 8. Risks, gaps, and gotchas' => '/^[#\s]*#\s*8\.\s*Risks[,\s]*\s*gaps[,\s]*\s*and\s+gotchas\s*$/im',
        '## 9. Reusable learnings' => '/^[#\s]*#\s*9\.\s*Reusable\s+learnings\s*$/im',
        '## 10. Continuation context' => '/^[#\s]*#\s*10\.\s*Continuation\s+context\s*$/im',
        '## 11. Final handoff' => '/^[#\s]*#\s*11\.\s*Final\s+handoff\s*$/im',
    ];

    /**
     * Validate a fork child's handoff response.
     *
     * @param string $candidateHandoff The handoff text produced by the fork child
     *
     * @return ForkHandoffValidationResultDTO Validation result
     */
    public function validate(string $candidateHandoff): ForkHandoffValidationResultDTO
    {
        if ('' === trim($candidateHandoff)) {
            return new ForkHandoffValidationResultDTO(
                valid: false,
                missingSections: array_keys(self::MANDATORY_SECTIONS),
                presentSections: [],
                repairInstruction: $this->buildRepairInstruction(
                    array_keys(self::MANDATORY_SECTIONS),
                    [],
                ),
            );
        }

        // Detect all present sections.
        $presentSections = [];
        foreach (self::ALL_SECTION_PATTERNS as $headerName => $pattern) {
            if (1 === preg_match($pattern, $candidateHandoff)) {
                $presentSections[] = $headerName;
            }
        }

        // Check mandatory sections.
        $missingSections = [];
        foreach (array_keys(self::MANDATORY_SECTIONS) as $mandatoryHeader) {
            if (!\in_array($mandatoryHeader, $presentSections, true)) {
                $missingSections[] = $mandatoryHeader;
            }
        }

        if ([] !== $missingSections) {
            return new ForkHandoffValidationResultDTO(
                valid: false,
                missingSections: $missingSections,
                presentSections: $presentSections,
                repairInstruction: $this->buildRepairInstruction($missingSections, $presentSections),
            );
        }

        // Check that section 1 contains a filesystem-changes statement.
        $section1Ok = $this->section1HasFilesystemStatement($candidateHandoff);

        if (!$section1Ok) {
            return new ForkHandoffValidationResultDTO(
                valid: false,
                missingSections: [],
                presentSections: $presentSections,
                repairInstruction: 'Section 1 (Result / status) must include a statement about filesystem changes: either mention specific files changed or state "No filesystem changes made."',
            );
        }

        return new ForkHandoffValidationResultDTO(
            valid: true,
            presentSections: $presentSections,
        );
    }

    /**
     * Get the list of mandatory section header names.
     *
     * @return list<string>
     */
    public function requiredSectionHeaders(): array
    {
        return array_keys(self::MANDATORY_SECTIONS);
    }

    /**
     * Build a repair instruction from the current state.
     *
     * @param list<string> $missingSections Section names that are missing
     * @param list<string> $presentSections Section names that were found
     *
     * @return string The repair instruction
     */
    private function buildRepairInstruction(array $missingSections, array $presentSections): string
    {
        $parts = [
            'Your previous response was not a valid fork handoff. It must follow the structured handoff format with exact section headers.',
        ];

        if ([] !== $missingSections) {
            $parts[] = 'Missing required section(s): '.implode(', ', $missingSections).'.';
        }

        $parts[] = 'Required sections: '.implode(', ', $this->requiredSectionHeaders()).'.';
        $parts[] = 'Please re-submit your response as a valid fork handoff report with all required sections and a clear filesystem-changes statement in section 1.';

        return implode("\n", $parts);
    }

    /**
     * Check whether section 1 contains a filesystem-changes statement.
     *
     * Looks for either "files changed" (implying specific files were
     * modified) or "No filesystem changes made" (implying the child
     * did not modify any files).
     *
     * This check is pragmatic — it looks for the words in section 1
     * context without strict parsing.
     */
    private function section1HasFilesystemStatement(string $handoff): bool
    {
        // Extract content between "## 1. Result / status" and the next section header.
        $pattern = '/^[#\s]*#\s*1\.\s*Result\s*\/\s*status\s*$(.*?)(?=^[#\s]*#\s*\d+\.)/ims';

        if (1 !== preg_match($pattern, $handoff, $matches)) {
            return false;
        }

        $section1Content = $matches[1];

        // Check for filesystem-changes statement.
        $hasFilesChanged = str_contains(strtolower($section1Content), 'files changed');
        $hasNoFilesystemChanges = str_contains(strtolower($section1Content), 'no filesystem changes made');

        return $hasFilesChanged || $hasNoFilesystemChanges;
    }
}
