<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\EditFileTool;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * @covers \Ineersa\CodingAgent\Tool\EditFileTool
 * @covers \Ineersa\CodingAgent\Tool\ToolDefinitionDTO
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
        $this->editFileTool = new EditFileTool($this->toolRuntime, $this->lockFactory);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    /* ── definition() tests ── */

    public function testDefinitionNameIsEdit(): void
    {
        $definition = $this->editFileTool->definition();

        $this->assertSame('edit', $definition->name);
    }

    public function testDefinitionHasDescription(): void
    {
        $definition = $this->editFileTool->definition();

        $this->assertNotEmpty($definition->description);
    }

    public function testDefinitionHandlerIsInvokable(): void
    {
        $definition = $this->editFileTool->definition();

        $this->assertTrue(method_exists($definition->handler, '__invoke'));
    }

    public function testDefinitionHasPromptLine(): void
    {
        $definition = $this->editFileTool->definition();

        $this->assertNotEmpty($definition->promptLine);
        $this->assertStringContainsString('edit', $definition->promptLine);
    }

    public function testDefinitionHasGuidelines(): void
    {
        $definition = $this->editFileTool->definition();

        $this->assertNotEmpty($definition->promptGuidelines);

        // Guidelines must describe unified diff, read line numbers, and @@ hunk headers
        $guidelinesText = implode(' ', $definition->promptGuidelines);
        $this->assertStringContainsString('unified diff', strtolower($guidelinesText));
        $this->assertStringContainsString('read', strtolower($guidelinesText));
        $this->assertStringContainsString('cat -n', $guidelinesText);
        $this->assertStringContainsString('line number', strtolower($guidelinesText));
        $this->assertStringContainsString('@@', $guidelinesText);
    }

    public function testDefinitionHasRetryGuidelines(): void
    {
        $definition = $this->editFileTool->definition();
        $guidelinesText = implode(' ', $definition->promptGuidelines);

        // Guidelines must instruct re-reading on failure and retry from current context
        $this->assertStringContainsString('read the current file', strtolower($guidelinesText));
        $this->assertStringContainsString('retry', strtolower($guidelinesText));
        $this->assertStringContainsString('trailing newline', strtolower($guidelinesText));
        $this->assertStringContainsString('current context', strtolower($guidelinesText));
    }

    public function testDefinitionJsonSchemaHasPathAndPatch(): void
    {
        $definition = $this->editFileTool->definition();
        $schema = $definition->parametersJsonSchema;

        $this->assertArrayHasKey('type', $schema);
        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('properties', $schema);
        $this->assertArrayHasKey('path', $schema['properties']);
        $this->assertArrayHasKey('patch', $schema['properties']);
        $this->assertArrayHasKey('required', $schema);
        $this->assertContains('path', $schema['required']);
        $this->assertContains('patch', $schema['required']);
        $this->assertArrayHasKey('additionalProperties', $schema);
        $this->assertFalse($schema['additionalProperties']);
    }

    public function testDefinitionImplementsHatfieldToolProviderInterface(): void
    {
        $this->assertTrue(method_exists($this->editFileTool, 'definition'));
    }

    /* ── ToolRegistry integration test ── */

    public function testRegistryExposesEditTool(): void
    {
        $registry = new ToolRegistry([$this->editFileTool]);
        $toolbox = new RegistryBackedToolbox($registry);
        $tools = $toolbox->getTools();

        $toolNames = array_map(static fn ($t) => $t->getName(), $tools);

        $this->assertContains('edit', $toolNames);
    }

    /* ── __invoke() success tests ── */

    public function testEditAppliesSingleHunkPatch(): void
    {
        $targetPath = $this->tmpDir.'/single_hunk.txt';
        $original = "line1\nline2\nline3\n";
        file_put_contents($targetPath, $original);

        $newContent = "line1\nmodified line2\nline3\n";
        $patch = $this->createUnifiedDiff($original, $newContent);

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        $this->assertStringContainsString('single_hunk.txt', $result);
        $this->assertStringContainsString('1 addition', $result);
        $this->assertStringContainsString('1 deletion', $result);
        $this->assertStringNotContainsString('@@', $result); // No full diff echo
        $this->assertSame($newContent, file_get_contents($targetPath));
    }

    public function testEditAppliesMultiHunkPatch(): void
    {
        $targetPath = $this->tmpDir.'/multi_hunk.txt';
        $original = "line1\nline2\nline3\nline4\nline5\n";
        file_put_contents($targetPath, $original);

        $modified = "line1\nmodified2\nline3\nmodified4\nline5\n";
        $patch = $this->createUnifiedDiff($original, $modified);

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        $this->assertStringNotContainsString('@@', $result); // No full diff echo
        $this->assertSame($modified, file_get_contents($targetPath));
    }

    public function testEditReturnsNoChangesMessageForIdenticalPatch(): void
    {
        $targetPath = $this->tmpDir.'/no_change.txt';
        $original = "a\nb\nc\n";
        file_put_contents($targetPath, $original);

        // Construct a patch that removes and re-adds the same content (net no-op).
        $patch = "--- a/file\n+++ b/file\n@@ -1,3 +1,3 @@\n a\n-b\n+b\n c\n";

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('No changes', $result);
        $this->assertSame($original, file_get_contents($targetPath));
    }

    /* ── __invoke() stale-hunk failure tests ── */

    public function testEditBadPatchRejectedOriginalUnchanged(): void
    {
        $targetPath = $this->tmpDir.'/bad_patch.txt';
        $original = "hello\nworld\n";
        file_put_contents($targetPath, $original);

        $patchOld = "something\ncompletely\ndifferent\n";
        $patchNew = "something\nnew\ndifferent\n";
        $patch = $this->createUnifiedDiff($patchOld, $patchNew);

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('E_PATCH_STALE', $e->getMessage());
            $this->assertTrue($e->retryable());
            // Original must be untouched
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

    public function testStaleHunkErrorIncludesCurrentFileContext(): void
    {
        $targetPath = $this->tmpDir.'/stale_context.txt';
        // Current file has 20 numbered lines
        $lines = [];
        for ($i = 1; $i <= 20; ++$i) {
            $lines[] = \sprintf('line %02d content', $i);
        }

        $original = implode("\n", $lines)."\n";
        file_put_contents($targetPath, $original);

        // Create a patch that references content NOT in the current file
        $fakeOld = "this does not exist\nin the current file\n";
        $fakeNew = "this does not exist\nmodified version\n";
        $patch = $this->createUnifiedDiff($fakeOld, $fakeNew);

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();

            // Must include error code
            $this->assertStringContainsString('E_PATCH_STALE', $message);

            // Must be retryable
            $this->assertTrue($e->retryable());

            // Must include current file context with line numbers
            $this->assertStringContainsString('Current file context', $message);

            // The hint should reference reading the file and regenerating
            $hint = $e->hint() ?? '';
            $this->assertStringContainsString('read', strtolower($hint));
            $this->assertStringContainsString('cat -n', $hint);

            // Original file must be untouched
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

    public function testStaleHunkContextIsBounded(): void
    {
        $targetPath = $this->tmpDir.'/bounded_context.txt';
        // Create a fairly large file (100 lines)
        $lines = [];
        for ($i = 1; $i <= 100; ++$i) {
            $lines[] = \sprintf('line %03d: some content here', $i);
        }

        $original = implode("\n", $lines)."\n";
        file_put_contents($targetPath, $original);

        // Generate a patch against completely foreign content
        $fakeOld = "not in this file\neither is this\n";
        $fakeNew = "not in this file\nbut different\n";
        $patch = $this->createUnifiedDiff($fakeOld, $fakeNew);

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();

            // The error message should NOT contain content from far-away lines
            $this->assertStringNotContainsString('line 080:', $message);
            $this->assertStringNotContainsString('line 100:', $message);

            // Message length should be bounded (not dumping the whole file)
            $this->assertLessThan(5000, \strlen($message));

            // Original must be untouched
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

    /**
     * Force a stale hunk failure centred on line ~49 and assert that
     * the context window shows nearby lines but NOT the extremes.
     */
    public function testStaleHunkContextCenteredOnFailedLine(): void
    {
        $targetPath = $this->tmpDir.'/midfile_context.txt';

        // File with 100 unique lines
        $lines = [];
        for ($i = 1; $i <= 100; ++$i) {
            $lines[] = \sprintf('line %03d content', $i);
        }

        $original = implode("\n", $lines)."\n";
        file_put_contents($targetPath, $original);

        // Create a patch whose context lines match near line 48-52 but the
        // removed line does not match, so GNU patch reports "Hunk #1 FAILED at 48."
        $patch = <<<'DIFF'
--- f
+++ f
@@ -48,5 +48,5 @@
 line 048 content
 line 049 content
-this line will NOT match
+this is new line
 line 051 content
 line 052 content
DIFF;

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();

            // Must include stale error code
            $this->assertStringContainsString('E_PATCH_STALE', $message);

            // Context window should include lines near the failure (around 48)
            $this->assertStringContainsString('Current file context', $message);

            // Lines far from the failure must NOT appear
            $this->assertStringNotContainsString('line 001', $message);
            $this->assertStringNotContainsString('line 100', $message);

            // Original must be untouched
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

    /* ── __invoke() malformed patch tests ── */

    public function testMalformedPatchGivesFormatError(): void
    {
        $targetPath = $this->tmpDir.'/malformed_target.txt';
        $original = "a\nb\nc\n";
        file_put_contents($targetPath, $original);

        // GNU patch reports "Only garbage was found in the patch input."
        // for input with no diff headers or hunk markers.
        $patch = "this is not a patch\njust random text\nno headers\nnohunks\n";

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();

            // Must include format error code specifically (garbage input is
            // detected deterministically on GNU patch 2.7.5+).
            $this->assertStringContainsString('[E_PATCH_FORMAT]', $message);

            // Must not include current-file context for format errors
            $this->assertStringNotContainsString('Current file context', $message);

            // Must be retryable with hint about proper format
            $this->assertTrue($e->retryable());
            $this->assertStringContainsString('unified diff', strtolower($e->hint() ?? ''));

            // Original must be untouched
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

    /* ── __invoke() no-op / already-applied tests ── */

    public function testAlreadyAppliedPatchReturnsNoopCode(): void
    {
        $targetPath = $this->tmpDir.'/already_applied.txt';

        // Create and apply a patch, then re-apply the same patch —
        // GNU patch -N detects "Reversed (or previously applied) patch
        // detected!  Skipping patch." and exits 1.
        $original = "line1\nline2\nline3\n";
        file_put_contents($targetPath, $original);

        $modified = "line1\nCHANGED\nline3\n";
        $patch = $this->createUnifiedDiff($original, $modified);

        // First apply: should succeed.
        $result1 = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
        $this->assertStringContainsString('Applied patch', $result1);
        $this->assertSame($modified, file_get_contents($targetPath));

        // Second apply of the same patch: should be detected as already applied.
        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException for already-applied patch');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString('[E_PATCH_NOOP]', $message);
            $this->assertTrue($e->retryable());

            $hint = $e->hint() ?? '';
            $this->assertStringContainsString('already applied', strtolower($hint));

            // File must remain unchanged from the first apply
            $this->assertSame($modified, file_get_contents($targetPath));
        }
    }

    /* ── __invoke() rejection tests ── */

    public function testEditMissingFileThrowsDirectingToWrite(): void
    {
        $targetPath = $this->tmpDir.'/does_not_exist.txt';
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -1 +1 @@
-old
+new
DIFF;

        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('does not exist');

        ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
    }

    /* ── __invoke() argument validation tests ── */

    public function testEditThrowsOnMissingPath(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"path" argument is required');

        ($this->editFileTool)(['patch' => 'some diff content']);
    }

    public function testEditThrowsOnEmptyPath(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"path" argument is required');

        ($this->editFileTool)(['path' => '', 'patch' => 'diff']);
    }

    public function testEditThrowsOnNonStringPath(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"path" argument is required');

        ($this->editFileTool)(['path' => 42, 'patch' => 'diff']);
    }

    public function testEditThrowsOnMissingPatch(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"patch" argument is required');

        ($this->editFileTool)(['path' => $this->tmpDir.'/test.txt']);
    }

    public function testEditThrowsOnNonStringPatch(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"patch" argument is required');

        ($this->editFileTool)(['path' => $this->tmpDir.'/test.txt', 'patch' => ['not', 'a', 'string']]);
    }

    /* ── Whitespace tolerance test ── */

    public function testEditWhitespaceTolerantMatch(): void
    {
        $targetPath = $this->tmpDir.'/whitespace.txt';
        $original = "apple    banana    cherry\n";
        file_put_contents($targetPath, $original);

        $patchOld = "apple banana cherry\n";
        $patchNew = "apple banana date\n";
        $patch = $this->createUnifiedDiff($patchOld, $patchNew);

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        $this->assertSame($patchNew, file_get_contents($targetPath));
    }

    /* ── Cancellation tests ── */

    public function testEditCancelledBeforeExecutionThrows(): void
    {
        $targetPath = $this->tmpDir.'/cancelled.txt';
        file_put_contents($targetPath, "original\ncontent\n");
        $originalContent = file_get_contents($targetPath);

        $token = $this->createToken(true);

        $this->contextAccessor->with(
            $this->contextWithToken($token),
            function () use ($targetPath): void {
                $this->expectException(\RuntimeException::class);
                $this->expectExceptionMessage('cancelled before start');

                ($this->editFileTool)([
                    'path' => $targetPath,
                    'patch' => 'dummy patch',
                ]);
            },
        );

        $this->assertSame($originalContent, file_get_contents($targetPath));
    }

    /* ── Trailing newline regression tests ── */

    public function testEditOnWriteNormalizedFileSucceeds(): void
    {
        $targetPath = $this->tmpDir.'/write_normalized.txt';
        $original = "hello from outside cwd\n";
        file_put_contents($targetPath, $original);

        $newContent = "hello from inside cwd\n";
        $patch = $this->createUnifiedDiff($original, $newContent);

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        $this->assertSame($newContent, file_get_contents($targetPath));
    }

    public function testEditTargetNotEndingWithNewlineIncludesEnrichedHint(): void
    {
        $targetPath = $this->tmpDir.'/no_trailing_newline.txt';

        // File without trailing newline, with content that does NOT match
        // the patch.  This guarantees a stale-hunk failure so the trailing-
        // newline hint is always observable.
        $original = "some content here\nlast line without newline";
        file_put_contents($targetPath, $original);

        // Patch against completely different content — always fails.
        $fakeOld = "completely different\ncontent here\n";
        $fakeNew = "completely different\nmodified version\n";
        $patch = $this->createUnifiedDiff($fakeOld, $fakeNew);

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $hint = $e->hint() ?? '';

            // Deterministic: the file lacks a trailing newline, and the
            // stale-hunk path always includes the newline hint.
            $this->assertStringContainsString('does not end with a newline', $hint);
            $this->assertStringContainsString('trailing newline', $hint);

            // The hint should also include stale guidance (prepended)
            $this->assertStringContainsString('read', strtolower($hint));

            $this->assertTrue($e->retryable());

            // Original must be untouched
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

    /* ── Symlink preservation tests ── */

    public function testEditViaSymlinkPreservesSymlinkAndUpdatesTarget(): void
    {
        // Create a real target file
        $realPath = $this->tmpDir.'/real_target.txt';
        $original = "original line 1\noriginal line 2\noriginal line 3\n";
        file_put_contents($realPath, $original);

        // Create a symlink pointing to it
        $linkPath = $this->tmpDir.'/link_to_target.txt';
        if (!@symlink($realPath, $linkPath)) {
            $this->markTestSkipped('symlink() not available on this platform.');

            return;
        }

        $this->assertTrue(is_link($linkPath), 'Symlink was not created');
        $this->assertSame($realPath, readlink($linkPath));

        // Edit through the symlink
        $newContent = "original line 1\nmodified line 2\noriginal line 3\n";
        $patch = $this->createUnifiedDiff($original, $newContent);

        $result = ($this->editFileTool)(['path' => $linkPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);

        // Symlink must still be a symlink
        $this->assertTrue(is_link($linkPath), 'Symlink was replaced with a regular file');

        // readlink must still point to the real target
        $this->assertSame($realPath, readlink($linkPath));

        // Target content must be updated
        $this->assertSame($newContent, file_get_contents($realPath));

        // Reading through symlink must show updated content
        $this->assertSame($newContent, file_get_contents($linkPath));
    }

    /* ── Hardlink preservation tests ── */

    public function testEditHardlinkUpdatesAllNames(): void
    {
        $path1 = $this->tmpDir.'/hardlink_a.txt';
        $path2 = $this->tmpDir.'/hardlink_b.txt';

        $original = "hardlink line 1\nhardlink line 2\nhardlink line 3\n";
        file_put_contents($path1, $original);

        // Create a hardlink
        if (!@link($path1, $path2)) {
            $this->markTestSkipped('link() not available on this platform (may require same filesystem).');

            return;
        }

        // Both paths should share the same inode
        $ino1 = stat($path1)['ino'];
        $ino2 = stat($path2)['ino'];
        $this->assertSame($ino1, $ino2, 'Hardlinks do not share inode');

        // Edit path2
        $newContent = "hardlink line 1\nmodified line 2\nhardlink line 3\n";
        $patch = $this->createUnifiedDiff($original, $newContent);

        $result = ($this->editFileTool)(['path' => $path2, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);

        // Both paths must show updated content
        $this->assertSame($newContent, file_get_contents($path1), 'Hardlink path1 content not updated');
        $this->assertSame($newContent, file_get_contents($path2), 'Hardlink path2 content not updated');

        // Both paths must still share the same inode (in-place write preserves inode)
        $newIno1 = stat($path1)['ino'];
        $newIno2 = stat($path2)['ino'];
        $this->assertSame($newIno1, $newIno2, 'Inodes diverged after edit');
        $this->assertSame($ino1, $newIno1, 'Inode changed — hardlink identity not preserved');
    }

    /* ── Write failure / permission tests ── */

    public function testEditUnwritableTargetReturnsInfraError(): void
    {
        // chmod-based write-denial is ineffective under root.
        if (0 === posix_getuid()) {
            $this->markTestSkipped('Cannot reliably deny writes when running as root.');

            return;
        }

        $targetPath = $this->tmpDir.'/unwritable.txt';
        $original = "content\n";
        file_put_contents($targetPath, $original);

        // Make the target read-only
        chmod($targetPath, 0o444);

        try {
            // Patch that would succeed on dry-run (context matches exactly)
            // but fails on in-place write because the file is read-only.
            $patch = "--- a/file\n+++ b/file\n@@ -1 +1 @@\n-content\n+new content\n";
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();

            // The write should fail — expect a write or infrastructure code.
            $this->assertMatchesRegularExpression('/E_PATCH_(?:WRITE|INFRA)/', $message);
            $this->assertTrue($e->retryable());

            // Original content must be restored by the rollback path.
            $this->assertSame($original, file_get_contents($targetPath));
        } finally {
            // Restore write permission for cleanup
            @chmod($targetPath, 0o644);
        }
    }

    /* ── helpers ── */

    /**
     * Create a unified diff between two content strings by shelling out to diff -u.
     */
    private function createUnifiedDiff(string $oldContent, string $newContent): string
    {
        $oldFile = tempnam(sys_get_temp_dir(), 'hatfield_diff_old_');
        $newFile = tempnam(sys_get_temp_dir(), 'hatfield_diff_new_');

        try {
            file_put_contents($oldFile, $oldContent);
            file_put_contents($newFile, $newContent);

            $diff = @shell_exec(\sprintf(
                'diff -u %s %s 2>/dev/null',
                escapeshellarg($oldFile),
                escapeshellarg($newFile),
            ));

            if (null === $diff || '' === $diff) {
                return '';
            }

            return $diff;
        } finally {
            if (is_file($oldFile)) {
                @unlink($oldFile);
            }
            if (is_file($newFile)) {
                @unlink($newFile);
            }
        }
    }

    private function createToken(bool $cancelled): CancellationTokenInterface
    {
        $token = $this->createStub(CancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturn($cancelled);

        return $token;
    }

    private function contextWithToken(CancellationTokenInterface $token): ToolContext
    {
        return new ToolContext(
            runId: 'edit_test_run',
            turnNo: 1,
            toolCallId: 'edit_call_1',
            toolName: 'edit',
            cancellationToken: $token,
            timeoutSeconds: 30,
        );
    }

}
