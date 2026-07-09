<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\Edit;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Tool\Edit\EditPatchParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EditPatchParser::class)]
final class EditPatchParserTest extends TestCase
{
    private EditPatchParser $parser;

    protected function setUp(): void
    {
        $this->parser = new EditPatchParser();
    }

    /**
     * Regression: a removal line `--- comment` is `-` + `-- comment`, not a legacy `---` header.
     */
    public function testRemovalLineStartingWithDoubleDashIsNotLegacyHeader(): void
    {
        $patch = <<<'PATCH'
@@
--- some comment
+-- replaced
PATCH;

        $chunks = $this->parser->parse($patch);
        $this->assertCount(1, $chunks);
        $this->assertSame(['-- some comment'], $chunks[0]->oldLines);
        $this->assertSame(['-- replaced'], $chunks[0]->newLines);
    }

    /**
     * Regression: an addition line `+++ value` is `+` + `++ value`, not a legacy `+++` header.
     */
    public function testAdditionLineStartingWithDoublePlusIsNotLegacyHeader(): void
    {
        $patch = <<<'PATCH'
@@
 line
+++ increment
PATCH;

        $chunks = $this->parser->parse($patch);
        $this->assertSame(['line', '++ increment'], $chunks[0]->newLines);
    }

    /**
     * Regression: `@@ -1 something` is a seek hint, not a numbered unified-diff header.
     */
    public function testSeekHintStartingWithNegativeOneIsAllowed(): void
    {
        $patch = <<<'PATCH'
@@ -1 something
 ctx
-ctx
+CTX
PATCH;

        $chunks = $this->parser->parse($patch);
        $this->assertSame(['-1 something'], $chunks[0]->seekHints);
    }

    public function testNumberedUnifiedDiffHeaderInPreambleIsRejected(): void
    {
        $patch = <<<'PATCH'
--- a/file.txt
+++ b/file.txt
@@ -1,3 +1,3 @@
 line
PATCH;

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('E_PATCH_FORMAT');
        $this->parser->parse($patch);
    }

    public function testNumberedUnifiedDiffHeaderAsFirstHunkLineIsRejected(): void
    {
        $patch = <<<'PATCH'
@@ -1,3 +1,3 @@
 line
-line
+LINE
PATCH;

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('Numbered unified-diff @@ headers are not supported');
        $this->parser->parse($patch);
    }
}
