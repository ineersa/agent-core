<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\EditFileTool;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * @covers \Ineersa\CodingAgent\Tool\EditFileTool
 * @covers \Ineersa\CodingAgent\Tool\Edit\EditPatchParser
 * @covers \Ineersa\CodingAgent\Tool\Edit\EditPatchApplicator
 * @covers \Ineersa\CodingAgent\Tool\Edit\SeekSequenceMatcher
 * @covers \Ineersa\CodingAgent\Tool\Edit\PatchApplier
 */
final class EditFileToolTest extends TestCase
{
    private StackToolExecutionContextAccessor $contextAccessor;
    private ToolRuntime $toolRuntime;
    private LockFactory $lockFactory;
    private string $tmpDir;
    private EditFileTool $editFileTool;

    protected function setUp(): void
    {
        $this->contextAccessor = new StackToolExecutionContextAccessor();
        $this->toolRuntime = new ToolRuntime($this->contextAccessor);
        $this->lockFactory = new LockFactory(new FlockStore());
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield_edit_test');
        $this->editFileTool = new EditFileTool($this->toolRuntime, $this->lockFactory, new NullLogger());
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testDefinitionPromptMentionsCodexStyleHunks(): void
    {
        $definition = $this->editFileTool->definition();

        $this->assertStringContainsString('diff prefix', $definition->description);
        $this->assertStringContainsString('leading space', $definition->description);

        $patchSchema = $definition->parametersJsonSchema['properties']['patch']['description'] ?? '';
        $this->assertStringContainsString('leading space', $patchSchema);
        $this->assertStringContainsString('unchanged', strtolower($patchSchema));
        $this->assertStringContainsString('Blank unchanged lines inside hunks', $patchSchema);
        $this->assertStringContainsString('one leading space', $patchSchema);

        $guidelines = implode(' ', $definition->promptGuidelines);
        $this->assertStringContainsString('@@', $guidelines);
        $this->assertStringContainsString('diff prefix', $guidelines);
        $this->assertStringContainsString('Blank unchanged lines inside hunks', $guidelines);
        $this->assertStringContainsString('one leading space', $guidelines);
        $this->assertStringContainsString('Compact example', $guidelines);
        $this->assertStringContainsString('no ---/+++', strtolower($guidelines));
        $this->assertStringNotContainsString('cat -n', $guidelines);
        $this->assertStringContainsString('numbered @@', strtolower($guidelines));
    }

    public function testEditAppliesSingleHunkPatch(): void
    {
        $targetPath = $this->tmpDir.'/single.txt';
        file_put_contents($targetPath, "line1\nline2\nline3\n");

        $patch = <<<'PATCH'
@@
 line1
-line2
+LINE2
 line3
PATCH;

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
        $this->assertStringContainsString('Applied patch', $result);
        $this->assertSame("line1\nLINE2\nline3\n", file_get_contents($targetPath));
    }

    public function testEditAppliesMultiHunkPatch(): void
    {
        $targetPath = $this->tmpDir.'/multi.txt';
        file_put_contents($targetPath, "a\nb\nc\nd\ne\n");

        $patch = <<<'PATCH'
@@
 a
-b
+B
@@
 d
-e
+E
PATCH;

        ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
        $this->assertSame("a\nB\nc\nd\nE\n", file_get_contents($targetPath));
    }

    public function testEditReturnsNoChangesMessageForIdenticalPatch(): void
    {
        $targetPath = $this->tmpDir.'/noop.txt';
        $original = "same\n";
        file_put_contents($targetPath, $original);

        $patch = <<<'PATCH'
@@
 same
PATCH;

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
        $this->assertStringContainsString('No changes', $result);
        $this->assertSame($original, file_get_contents($targetPath));
    }

    public function testStaleHunkIncludesCurrentFileContext(): void
    {
        $targetPath = $this->tmpDir.'/stale.txt';
        file_put_contents($targetPath, "alpha\nbeta\ngamma\n");

        $patch = <<<'PATCH'
@@
 missing
-old
+new
PATCH;

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('E_PATCH_STALE', $e->getMessage());
            $this->assertStringContainsString('Current file context', $e->getMessage());
            $this->assertSame("alpha\nbeta\ngamma\n", file_get_contents($targetPath));
        }
    }

    public function testAmbiguousSeekHintFailsFast(): void
    {
        $targetPath = $this->tmpDir.'/ambiguous.txt';
        file_put_contents($targetPath, "dup\nmiddle\ndup\nend\n");

        $patch = <<<'PATCH'
@@ dup
 dup
-old
+new
PATCH;

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('E_PATCH_AMBIGUOUS', $e->getMessage());
            $this->assertSame("dup\nmiddle\ndup\nend\n", file_get_contents($targetPath));
        }
    }

    public function testStackedSeekHintsApply(): void
    {
        $targetPath = $this->tmpDir.'/stacked.txt';
        file_put_contents($targetPath, "class Foo\n  method one\n  method two\nclass Bar\n");

        $patch = <<<'PATCH'
@@ class Foo
@@   method two
-  method two
+  method TWO
PATCH;

        ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
        $this->assertStringContainsString('method TWO', file_get_contents($targetPath));
    }

    public function testEofAppendUsesEndOfFileMarker(): void
    {
        $targetPath = $this->tmpDir.'/eof.txt';
        file_put_contents($targetPath, "keep\n");

        $patch = <<<'PATCH'
@@
+appended
*** End of File
PATCH;

        ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
        $this->assertSame("keep\nappended\n", file_get_contents($targetPath));
    }

    public function testWhitespaceTolerantTrimEndMatch(): void
    {
        $targetPath = $this->tmpDir.'/trim.txt';
        file_put_contents($targetPath, "value   \nnext\n");

        $patch = <<<'PATCH'
@@ value   
-value   
+VALUE
PATCH;

        ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
        $this->assertSame("VALUE\nnext\n", file_get_contents($targetPath));
    }

    public function testIssue245LeadingHeavyContextApplies(): void
    {
        $targetPath = $this->tmpDir.'/issue245.txt';
        $content = '';
        for ($i = 1; $i <= 20; ++$i) {
            $content .= "line {$i}\n";
        }
        $content .= "target block\n";
        $content .= "after\n";
        file_put_contents($targetPath, $content);

        $patch = <<<'PATCH'
@@ line 18
 line 18
 line 19
 line 20
-target block
+TARGET BLOCK
 after
PATCH;

        ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
        $this->assertStringContainsString("TARGET BLOCK\n", file_get_contents($targetPath));
    }

    public function testMalformedOldFormatRejected(): void
    {
        $targetPath = $this->tmpDir.'/format.txt';
        file_put_contents($targetPath, "x\n");

        $patch = "--- a/file\n+++ b/file\n@@ -1 +1 @@\n x\n";

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('E_PATCH_FORMAT', $e->getMessage());
            $this->assertSame("x\n", file_get_contents($targetPath));
        }
    }

    public function testEditMissingFileThrowsDirectingToWrite(): void
    {
        $this->expectException(ToolCallException::class);
        ($this->editFileTool)(['path' => $this->tmpDir.'/missing.txt', 'patch' => "@@\n x\n"]);
    }

    public function testEditCancelledBeforeExecutionThrows(): void
    {
        $targetPath = $this->tmpDir.'/cancel.txt';
        file_put_contents($targetPath, "a\n");
        $original = file_get_contents($targetPath);

        $token = $this->createStub(CancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturn(true);

        $this->contextAccessor->with(
            new ToolContext('run', 1, 'call', 'edit', $token, 30),
            function () use ($targetPath): void {
                $this->expectException(\RuntimeException::class);
                $this->expectExceptionMessage('cancelled before start');
                ($this->editFileTool)(['path' => $targetPath, 'patch' => "@@\n a\n"]);
            },
        );

        $this->assertSame($original, file_get_contents($targetPath));
    }

    public function testEditViaSymlinkPreservesSymlinkAndUpdatesTarget(): void
    {
        $targetPath = $this->tmpDir.'/real.txt';
        $linkPath = $this->tmpDir.'/link.txt';
        file_put_contents($targetPath, "one\n");
        symlink($targetPath, $linkPath);

        $patch = <<<'PATCH'
@@ one
-one
+ONE
PATCH;

        ($this->editFileTool)(['path' => $linkPath, 'patch' => $patch]);
        $this->assertTrue(is_link($linkPath));
        $this->assertSame("ONE\n", file_get_contents($targetPath));
    }

    public function testEditHardlinkUpdatesAllNames(): void
    {
        $pathA = $this->tmpDir.'/a.txt';
        $pathB = $this->tmpDir.'/b.txt';
        file_put_contents($pathA, "shared\n");
        link($pathA, $pathB);

        $patch = <<<'PATCH'
@@ shared
-shared
+SHARED
PATCH;

        ($this->editFileTool)(['path' => $pathA, 'patch' => $patch]);
        $this->assertSame("SHARED\n", file_get_contents($pathB));
    }

    public function testEditUnwritableTargetReturnsWriteError(): void
    {
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('chmod write test is Unix-specific');
        }

        $targetPath = $this->tmpDir.'/readonly.txt';
        file_put_contents($targetPath, "locked\n");
        chmod($targetPath, 0444);

        $patch = <<<'PATCH'
@@ locked
-locked
+LOCKED
PATCH;

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('E_PATCH_WRITE', $e->getMessage());
        } finally {
            chmod($targetPath, 0644);
        }
    }

    /**
     * Regression: multi-hunk patches that grow line count must mark changed lines in patched coordinates.
     */
    public function testMultiHunkSuccessContextMarksSecondHunkInPatchedLineNumbers(): void
    {
        $targetPath = $this->tmpDir.'/multi_ctx.txt';
        file_put_contents($targetPath, "a\nb\nc\nd\ne\n");

        $patch = <<<'PATCH'
@@
 a
-b
+B
@@
 d
-e
+E
+F
PATCH;

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
        $this->assertStringContainsString('Updated file context', $result);
        // Second hunk inserts an extra line; changed region is around patched line 5 (E), not original line 4.
        $this->assertMatchesRegularExpression('/→\s+5:/', $result);
        $this->assertDoesNotMatchRegularExpression('/→\s+4:\s+E/', $result);
        $this->assertSame("a\nB\nc\nd\nE\nF\n", file_get_contents($targetPath));
    }

    /**
     * Regression: pure deletion hunks must still return updated-file context near the deletion site.
     */
    public function testPureDeletionWithoutContextShowsUpdatedFileContext(): void
    {
        $targetPath = $this->tmpDir.'/pure_delete.txt';
        file_put_contents($targetPath, 'alpha
beta
gamma
');

        $patch = <<<'PATCH'
@@
-beta
PATCH;

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
        $this->assertStringContainsString('Updated file context', $result);
        $this->assertMatchesRegularExpression('/→\s+2:/', $result);
        $this->assertSame('alpha
gamma
', file_get_contents($targetPath));
    }

    /**
     * Regression: trailing-empty context in a hunk must not match via shortened pattern and delete following lines.
     */
    public function testPhantomTrailingBlankContextDoesNotDeleteFollowingLine(): void
    {
        $targetPath = $this->tmpDir.'/phantom_blank.txt';
        file_put_contents($targetPath, "foo\nbar\n");

        $patch = <<<'PATCH'
@@
 foo
 
-bar
+baz
PATCH;

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('E_PATCH_STALE', $e->getMessage());
        }

        $this->assertSame("foo\nbar\n", file_get_contents($targetPath));
    }

    /**
     * Regression: EOF append to an empty file must not insert a leading newline.
     */
    public function testEofAppendToEmptyFileHasNoLeadingNewline(): void
    {
        $targetPath = $this->tmpDir.'/empty_eof.txt';
        file_put_contents($targetPath, '');

        $patch = <<<'PATCH'
@@
+only
*** End of File
PATCH;

        ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
        $this->assertSame('only', file_get_contents($targetPath));
    }

    public function testEditRemovesLineStartingWithDoubleDash(): void
    {
        $targetPath = $this->tmpDir.'/sql_comment.txt';
        file_put_contents($targetPath, "keep\n-- drop me\ntail\n");

        $patch = <<<'PATCH'
@@
 keep
--- drop me
+-- kept
 tail
PATCH;

        ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
        $this->assertSame("keep\n-- kept\ntail\n", file_get_contents($targetPath));
    }

    public function testSeekHintNegativeOnePrefixApplies(): void
    {
        $targetPath = $this->tmpDir.'/seek_neg.txt';
        file_put_contents($targetPath, "alpha\n-1 something\nbeta\n");

        $patch = <<<'PATCH'
@@ -1 something
 -1 something
-beta
+BETA
PATCH;

        ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
        $this->assertSame("alpha\n-1 something\nBETA\n", file_get_contents($targetPath));
    }
}
