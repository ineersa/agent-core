<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Transcript;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\Tui\Transcript\TranscriptToolResultFacts;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Behavior proof for {@see TranscriptToolResultFacts}: result-body presentation
 * facts consumed by the pairing policy's scoring and the tool renderer.
 */
final class TranscriptToolResultFactsTest extends TestCase
{
    private TranscriptToolResultFacts $facts;

    protected function setUp(): void
    {
        $this->facts = new TranscriptToolResultFacts();
    }

    #[Test]
    public function successfulEditResultBodyIsCompactedBeforeFileContextMarker(): void
    {
        $block = new TranscriptBlock(
            id: 'r-1',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'run1',
            seq: 1,
            meta: [
                'tool_name' => 'edit',
                'result' => "Applied patch to target.txt (1 addition, 1 deletion)\n\nUpdated file context:\n@@ -1 +1 @@\n-before\n+after",
            ],
        );

        $this->assertSame(
            'Applied patch to target.txt (1 addition, 1 deletion)',
            $this->facts->toolResultBodyText($block),
        );
    }

    #[Test]
    public function fullRenderEditResultKeepsFileContextMarker(): void
    {
        $block = new TranscriptBlock(
            id: 'r-1',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'run1',
            seq: 1,
            meta: [
                'tool_name' => 'edit',
                'is_error' => true,
                'result' => "Applied patch to target.txt\n\nUpdated file context:\n@@ -1 +1 @@\n-before\n+after",
            ],
        );

        $this->assertStringContainsString('Updated file context:', $this->facts->toolResultBodyText($block));
    }

    #[Test]
    public function nonEditToolResultIsNotCompactedAtMarker(): void
    {
        $block = new TranscriptBlock(
            id: 'r-1',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'run1',
            seq: 1,
            meta: [
                'tool_name' => 'write',
                'result' => "Wrote target.txt\n\nUpdated file context:\n@@ -1 +1 @@\n-before\n+after",
            ],
        );

        $this->assertSame(
            "Wrote target.txt\n\nUpdated file context:\n@@ -1 +1 @@\n-before\n+after",
            $this->facts->toolResultBodyText($block),
        );
    }

    #[Test]
    public function toolResultBodyTextFallsBackToTextForUnknownBody(): void
    {
        $block = new TranscriptBlock(
            id: 'r-1',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'run1',
            seq: 1,
            text: 'Tool result',
        );

        $this->assertSame('', $this->facts->toolResultBodyText($block));
    }

    #[Test]
    public function toolResultBodyTextSuppressesTextEqualToStringToolName(): void
    {
        $block = new TranscriptBlock(
            id: 'r-1',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'run1',
            seq: 1,
            text: 'read',
            meta: ['tool_name' => 'read'],
        );

        $this->assertSame('', $this->facts->toolResultBodyText($block));
    }

    #[Test]
    public function toolResultBodyTextPassesThroughScalarResult(): void
    {
        $block = new TranscriptBlock(
            id: 'r-1',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'run1',
            seq: 1,
            meta: ['result' => 42],
        );

        $this->assertSame('42', $this->facts->toolResultBodyText($block));
    }

    #[Test]
    public function toolResultBodyTextDumpsArrayResultAsYaml(): void
    {
        $block = new TranscriptBlock(
            id: 'r-1',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'run1',
            seq: 1,
            meta: ['result' => ['a' => 'b']],
        );

        $this->assertSame(
            trim(Yaml::dump(['a' => 'b'], 4, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK)),
            $this->facts->toolResultBodyText($block),
        );
    }

    #[Test]
    public function metaIsTruthyAcceptsBooleanAndStringOnes(): void
    {
        $this->assertTrue($this->facts->metaIsTruthy(true));
        $this->assertTrue($this->facts->metaIsTruthy(1));
        $this->assertTrue($this->facts->metaIsTruthy('1'));
        $this->assertFalse($this->facts->metaIsTruthy(false));
        $this->assertFalse($this->facts->metaIsTruthy(0));
        $this->assertFalse($this->facts->metaIsTruthy('yes'));
        $this->assertFalse($this->facts->metaIsTruthy(null));
    }

    #[Test]
    public function toolResultIsFullRenderCoversErrorCancelAndTimeout(): void
    {
        foreach (['is_error', 'cancelled', 'timed_out'] as $flag) {
            $block = new TranscriptBlock(
                id: 'r-'.$flag,
                kind: TranscriptBlockKindEnum::ToolResult,
                runId: 'run1',
                seq: 1,
                meta: [$flag => true],
            );

            $this->assertTrue($this->facts->toolResultIsFullRender($block), $flag.' should force full render');
        }

        $ok = new TranscriptBlock(
            id: 'r-ok',
            kind: TranscriptBlockKindEnum::ToolResult,
            runId: 'run1',
            seq: 1,
            meta: ['is_error' => false],
        );
        $this->assertFalse($this->facts->toolResultIsFullRender($ok));
    }
}
