<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\CodingAgent\Agent\Fork\ForkHandoffValidationResultDTO;
use Ineersa\CodingAgent\Agent\Fork\ForkHandoffValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ForkHandoffValidator.
 *
 * Test thesis:
 *   - A valid complete handoff with all mandatory sections passes.
 *   - A handoff missing a mandatory section fails with that section
 *     in missingSections and a non-empty repairInstruction.
 *   - A conversational/non-structured reply fails.
 *   - Empty input fails with all mandatory sections listed as missing.
 *   - The filesystem statement check in section 1 is enforced.
 */
#[CoversClass(ForkHandoffValidator::class)]
#[CoversClass(ForkHandoffValidationResultDTO::class)]
final class ForkHandoffValidatorTest extends TestCase
{
    private ForkHandoffValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new ForkHandoffValidator();
    }

    // ── Valid handoff ────────────────────────────────────────────────────

    public function testValidCompleteHandoffPasses(): void
    {
        $handoff = <<<'HANDOFF'
## 1. Result / status

Task complete. 3 files changed.

## 2. Scope and authority

In scope: contracts DTOs. Out of scope: process launching.

## 3. Navigation / tool trail

Read config files, studied existing patterns.

## 4. Evidence and context discovered

Key findings documented below.

## 5. Changes made

Created 5 new files, edited 2 existing ones.

## 6. Data/control flow

Entry points and call chain described.

## 7. Validation performed

All Castor commands pass.

## 8. Risks, gaps, and gotchas

No known issues.

## 9. Reusable learnings

N/A

## 10. Continuation context

Start from the ForkContextBuilder.

## 11. Final handoff

Ready for review.
HANDOFF;

        $result = $this->validator->validate($handoff);

        self::assertTrue($result->valid);
        self::assertCount(0, $result->missingSections);
        self::assertNull($result->repairInstruction);
    }

    // ── Missing mandatory sections ───────────────────────────────────────

    public function testMissingSectionFails(): void
    {
        $handoff = <<<'HANDOFF'
## 1. Result / status

Task complete. 3 files changed.

## 5. Changes made

Created files.

## 7. Validation performed

All pass.

## 11. Final handoff

Done.
HANDOFF;

        $result = $this->validator->validate($handoff);

        // Should still pass because all mandatory sections (1, 5, 11) are present.
        self::assertTrue($result->valid);
    }

    public function testMissingMandatorySection11Fails(): void
    {
        $handoff = <<<'HANDOFF'
## 1. Result / status

Task complete. No filesystem changes made.

## 5. Changes made

No filesystem changes made.

## 2. Scope and authority

In scope.

## 11. Final handoff

Done.
HANDOFF;

        $result = $this->validator->validate($handoff);

        self::assertTrue($result->valid);
    }

    public function testMissingSection1Fails(): void
    {
        $handoff = <<<'HANDOFF'
## 2. Scope and authority

In scope.

## 5. Changes made

Created files.

## 11. Final handoff

Done.
HANDOFF;

        $result = $this->validator->validate($handoff);

        self::assertFalse($result->valid);
        self::assertContains('## 1. Result / status', $result->missingSections);
        self::assertNotNull($result->repairInstruction);
    }

    public function testMissingSection5Fails(): void
    {
        $handoff = <<<'HANDOFF'
## 1. Result / status

Task complete. 3 files changed.

## 2. Scope and authority

In scope.

## 11. Final handoff

Done.
HANDOFF;

        $result = $this->validator->validate($handoff);

        self::assertFalse($result->valid);
        self::assertContains('## 5. Changes made', $result->missingSections);
        self::assertNotNull($result->repairInstruction);
    }

    public function testMissingAllMandatorySectionsFails(): void
    {
        $handoff = <<<'HANDOFF'
## 2. Scope and authority

In scope.

## 8. Risks, gaps, and gotchas

None.
HANDOFF;

        $result = $this->validator->validate($handoff);

        self::assertFalse($result->valid);
        self::assertCount(3, $result->missingSections);
        self::assertContains('## 1. Result / status', $result->missingSections);
        self::assertContains('## 5. Changes made', $result->missingSections);
        self::assertContains('## 11. Final handoff', $result->missingSections);
    }

    // ── Conversational / non-structured reply ────────────────────────────

    public function testConversationalReplyFails(): void
    {
        $handoff = 'I completed the task. Everything went well.';

        $result = $this->validator->validate($handoff);

        self::assertFalse($result->valid);
        self::assertNotEmpty($result->missingSections);
        self::assertNotNull($result->repairInstruction);
    }

    // ── Empty input ──────────────────────────────────────────────────────

    public function testEmptyInputFails(): void
    {
        $result = $this->validator->validate('');

        self::assertFalse($result->valid);
        self::assertCount(3, $result->missingSections);
        self::assertNotNull($result->repairInstruction);
    }

    // ── Filesystem statement check ───────────────────────────────────────

    public function testSection1MissingFilesystemStatementFails(): void
    {
        $handoff = <<<'HANDOFF'
## 1. Result / status

The task is complete. Everything went well.

## 5. Changes made

Created files.

## 11. Final handoff

Done.
HANDOFF;

        $result = $this->validator->validate($handoff);

        self::assertFalse($result->valid);
        self::assertNotNull($result->repairInstruction);
        self::assertStringContainsString('filesystem changes', $result->repairInstruction);
    }

    public function testSection1WithNoFilesystemChangesPasses(): void
    {
        $handoff = <<<'HANDOFF'
## 1. Result / status

No filesystem changes made.

## 5. Changes made

No filesystem changes made.

## 11. Final handoff

Done.
HANDOFF;

        $result = $this->validator->validate($handoff);

        self::assertTrue($result->valid);
    }

    public function testSection1WithFilesChangedPasses(): void
    {
        $handoff = <<<'HANDOFF'
## 1. Result / status

Task complete. 3 files changed.

## 5. Changes made

Updated files.

## 11. Final handoff

Done.
HANDOFF;

        $result = $this->validator->validate($handoff);

        self::assertTrue($result->valid);
    }

    // ── Required section headers helper ──────────────────────────────────

    public function testRequiredSectionHeaders(): void
    {
        $headers = $this->validator->requiredSectionHeaders();

        self::assertCount(3, $headers);
        self::assertContains('## 1. Result / status', $headers);
        self::assertContains('## 5. Changes made', $headers);
        self::assertContains('## 11. Final handoff', $headers);
    }
}
