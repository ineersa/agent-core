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
        $guidelines = implode(' ', $definition->promptGuidelines);

        $this->assertStringContainsString('@@', $guidelines);
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
}
