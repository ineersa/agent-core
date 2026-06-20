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
use Psr\Log\NullLogger;
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
        $this->editFileTool = new EditFileTool($this->toolRuntime, $this->lockFactory, new NullLogger());
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

        // Guidelines must warn against common LLM mistakes observed in smoke testing
        $this->assertStringContainsString('markdown code fences', strtolower($guidelinesText));
        $this->assertStringContainsString('end new file', strtolower($guidelinesText));
        $this->assertStringContainsString('line-number prefix', strtolower($guidelinesText));
        $this->assertStringContainsString('trailing newline', strtolower($guidelinesText));
        $this->assertStringContainsString('repairs', strtolower($guidelinesText));

        // Guidelines must include generic diff-marker examples for file
        // content that itself starts with '-' or '+' (session-5 regression).
        $this->assertStringContainsString('-foo', $guidelinesText);
        $this->assertStringContainsString('--foo', $guidelinesText);
        $this->assertStringContainsString('+-foo', $guidelinesText);

        // Guidelines must instruct re-reading when success stats contradict intent
        $this->assertStringContainsString('contradict', strtolower($guidelinesText));
        $this->assertStringContainsString('re-read', strtolower($guidelinesText));
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

    /**
     * When dry-run validation fails, the model-visible error must
     * unambiguously state that no changes were applied — even when
     * GNU patch might report hunks that "succeeded" with fuzz
     * alongside failed hunks (session-5 regression).
     */
    public function testDryRunFailureMessageStatesNoChangesApplied(): void
    {
        $targetPath = $this->tmpDir.'/no_changes_msg.txt';
        $original = "hello\nworld\n";
        file_put_contents($targetPath, $original);

        // Simple stale-hunk patch (content not in file)
        $patchOld = "something\ncompletely\ndifferent\n";
        $patchNew = "something\nnew\ndifferent\n";
        $patch = $this->createUnifiedDiff($patchOld, $patchNew);

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();

            // Must include the all-or-nothing preface
            $this->assertStringContainsString('No changes were applied', $message);
            $this->assertStringContainsString('dry-run validation failed', $message);
            $this->assertStringContainsString("Any 'Hunk succeeded'", $message);
            $this->assertStringContainsString('diagnostics only', $message);
            $this->assertStringContainsString('target file is untouched', $message);

            // Must still include stale error code
            $this->assertStringContainsString('E_PATCH_STALE', $message);

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

            // Lines near the failed hunk must actually appear
            $this->assertStringContainsString('line 048 content', $message);
            $this->assertStringContainsString('line 049 content', $message);
            $this->assertStringContainsString('line 051 content', $message);

            // Failed line must be marked with → in context output
            $this->assertStringContainsString("\u{2192}", $message);

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

    /* ── CRLF / lone-CR normalisation tests ── */

    public function testCrlfContentDoesNotLeakCarriageReturnsIntoContext(): void
    {
        $targetPath = $this->tmpDir.'/crlf_content.txt';

        // File with CRLF line endings
        $original = "line 01 content\r\nline 02 content\r\nline 03 content\r\n";
        file_put_contents($targetPath, $original);

        // Patch against foreign content to force stale-hunk failure
        $fakeOld = "not present\nin this file\n";
        $fakeNew = "not present\nmodified\n";
        $patch = $this->createUnifiedDiff($fakeOld, $fakeNew);

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();

            // Context must not expose raw carriage returns
            $this->assertStringNotContainsString("\r", $message);

            // Content must still be readable (lines preserved without CR)
            $this->assertStringContainsString('line 01 content', $message);

            // Original untouched (including CRLF bytes)
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

    public function testLoneCrContentDoesNotLeakCarriageReturnsIntoContext(): void
    {
        $targetPath = $this->tmpDir.'/lone_cr_content.txt';

        // File with lone-CR line endings (macOS Classic style)
        $original = "line 01 content\rline 02 content\rline 03 content\r";
        file_put_contents($targetPath, $original);

        // Patch against foreign content to force stale-hunk failure
        $fakeOld = "not present\nin this file\n";
        $fakeNew = "not present\nmodified\n";
        $patch = $this->createUnifiedDiff($fakeOld, $fakeNew);

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();

            // Context must not expose raw carriage returns
            $this->assertStringNotContainsString("\r", $message);

            // Content must still be readable (lines preserved without CR)
            $this->assertStringContainsString('line 01 content', $message);

            // Original untouched (including CR bytes)
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

    /* ── Patch normalization / auto-repair tests ── */

    public function testHunkCountMismatchIsAutoRepairedAndApplied(): void
    {
        // 20-line target file
        $targetPath = $this->tmpDir.'/hunk_repair_target.txt';
        $lines = [];
        for ($i = 1; $i <= 20; ++$i) {
            $lines[] = \sprintf('line %02d', $i);
        }

        $original = implode("\n", $lines)."\n";
        file_put_contents($targetPath, $original);

        // Patch with intentionally WRONG hunk header counts.
        // Header says @@ -1,3 +1,10 @@ (10 new lines) but the body only
        // has 5 added lines (+ actual 3-context = 5 new, 3-context+3-removed = 6 old).
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -1,3 +1,10 @@
 line 01
-line 02
-line 03
+REPLACEMENT 02
+REPLACEMENT 03
+EXTRA 04
+EXTRA 05
+EXTRA 06
DIFF;

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);

        // Verify the repaired hunk applied correctly
        $expected = "line 01\nREPLACEMENT 02\nREPLACEMENT 03\nEXTRA 04\nEXTRA 05\nEXTRA 06\n";
        for ($i = 4; $i <= 20; ++$i) {
            $expected .= \sprintf("line %02d\n", $i);
        }
        $this->assertSame($expected, file_get_contents($targetPath));
    }

    public function testMultiHunkCountMismatchAllRepaired(): void
    {
        // 10-line target file
        $targetPath = $this->tmpDir.'/multi_hunk_repair_target.txt';
        $lines = [];
        for ($i = 1; $i <= 10; ++$i) {
            $lines[] = \sprintf('L%02d content', $i);
        }

        $original = implode("\n", $lines)."\n";
        file_put_contents($targetPath, $original);

        // Patch with two hunks, both with wrong counts
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -2,2 +2,99 @@
 L02 content
-L03 content
+CHANGED L03
@@ -7,3 +7,50 @@
 L07 content
-L08 content
-L09 content
+CHANGED L08
+CHANGED L09
DIFF;

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);

        $expected = "L01 content\nL02 content\nCHANGED L03\nL04 content\nL05 content\nL06 content\nL07 content\nCHANGED L08\nCHANGED L09\nL10 content\n";
        $this->assertSame($expected, file_get_contents($targetPath));
    }

    public function testPatchWrappedInMarkdownFenceAndTrailersIsNormalized(): void
    {
        $targetPath = $this->tmpDir.'/fence_trailer_target.txt';
        $original = "line1\nline2\nline3\nline4\n";
        file_put_contents($targetPath, $original);

        // Patch wrapped in markdown fence, ending with hallucinated
        // "--- End new file ---" trailer and missing a final newline
        // before the closing fence.
        $patch = <<<'PATCH'
```diff
--- a/file
+++ b/file
@@ -1,4 +1,5 @@
 line1
-line2
+CHANGED2
 line3
 line4
+EXTRA5
--- End new file ---
```
PATCH;

        $expected = "line1\nCHANGED2\nline3\nline4\nEXTRA5\n";

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        $this->assertSame($expected, file_get_contents($targetPath));
    }

    /**
     * When the model appends TWO hallucinated non-diff trailers
     * (e.g. "--- End new file ---" then "--- End file ---"), the
     * normalizer must strip all of them so the patch applies cleanly
     * (session-5 regression).
     */
    public function testMultipleTrailingArtifactsRemovedBeforePatch(): void
    {
        $targetPath = $this->tmpDir.'/multi_trailer_target.txt';
        $original = "line A\nline B\nline C\n";
        file_put_contents($targetPath, $original);

        // Patch with TWO stacked trailers at the end
        $patch = <<<'PATCH'
--- a/file
+++ b/file
@@ -1,3 +1,3 @@
 line A
-line B
+CHANGED B
 line C
--- End new file ---
--- End file ---
PATCH;

        $expected = "line A\nCHANGED B\nline C\n";

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        $this->assertSame($expected, file_get_contents($targetPath));
    }

    public function testMalformedPatchMissingHunkHeaderClassifiedAsFormat(): void
    {
        $targetPath = $this->tmpDir.'/missing_hunk_target.txt';
        $original = "a\nb\nc\n";
        file_put_contents($targetPath, $original);

        // Patch with ---/+++ headers but no @@ hunk header at all —
        // GNU patch reports "Only garbage was found in the patch input."
        // The hint must mention newline, markdown fences, and hunk counts.
        $patch = "--- a/file\n+++ b/file\nno proper hunk header\n";

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();

            // Must be classified as format, not stale
            $this->assertStringContainsString('[E_PATCH_FORMAT]', $message);

            // Hint should mention newline, fences/trailers, and hunk counts
            $hint = $e->hint() ?? '';
            $this->assertStringContainsString('newline', strtolower($hint));
            $this->assertStringContainsString('markdown', strtolower($hint));
            $this->assertStringContainsString('hunk', strtolower($hint));

            $this->assertTrue($e->retryable());

            // Original must be untouched
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

    /**
     * Truncated hunk where BOTH declared old and new counts exceed actual
     * body content must NOT be auto-repaired — the truncation heuristic
     * leaves the mismatch for GNU patch to reject as E_PATCH_FORMAT.
     */
    public function testTruncatedHunkBothSidesUnderCountRejectedAsFormat(): void
    {
        $targetPath = $this->tmpDir.'/truncated_hunk_target.txt';
        // 5-line file: lines 1–5
        $original = "line 1\nline 2\nline 3\nline 4\nline 5\n";
        file_put_contents($targetPath, $original);

        // Hunk header declares @@ -2,4 +2,4 @@ (replace 4 lines at line 2
        // with 4 new lines) but the body only has 2 actual + lines and
        // only context lines before them — far less than declared on both
        // sides.  This simulates a truncated LLM output.
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -2,4 +2,4 @@
 line 2
-line 3
+CHANGED 3
DIFF;

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException for truncated hunk');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();
            $hint = $e->hint() ?? '';

            // Must be classified as E_PATCH_FORMAT (truncation safety
            // prevents repair; GNU patch rejects the mismatch).
            $this->assertStringContainsString('[E_PATCH_FORMAT]', $message);
            $this->assertTrue($e->retryable());

            // Hint must specifically diagnose truncation, not generic format
            $this->assertStringContainsString('hunk header declared more lines', $hint);
            $this->assertStringContainsString('truncated', strtolower($hint));
            $this->assertStringContainsString('complete hunk', strtolower($hint));

            // File must be completely unchanged
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

    /**
     * A perfectly valid unified diff (standard diff -u output) must pass
     * through the normalizer without corruption.  If the trailing empty
     * explode artifact were counted as a context line the repaired hunk
     * count would be inflated by +1/+1 and could misalign.
     *
     * Uses real diff -u output via createUnifiedDiff() to guarantee a
     * correct patch header and body with no count mismatches.
     */
    public function testPerfectlyCountedDiffNotCorruptedByNormalizer(): void
    {
        $targetPath = $this->tmpDir.'/perfect_diff_target.txt';
        $original = "line 001\nline 002\nline 003\nline 004\nline 005\n";
        file_put_contents($targetPath, $original);

        // Replace lines 2-3 with new content
        $expected = "line 001\nREPLACED 002\nREPLACED 003\nline 004\nline 005\n";
        $patch = $this->createUnifiedDiff($original, $expected);

        // Must not be empty (sanity check)
        $this->assertNotEmpty($patch);

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        $this->assertSame($expected, file_get_contents($targetPath));
    }

    /**
     * A zero-count hunk (pure insertion, e.g. @@ -X,0 +Y,N @@) must
     * pass through the normalizer uncorrupted — the earlier max(1,…)
     * flooring would rewrite 0→1 and change insertion-at-position to
     * modification-of, breaking the edit semantics.
     *
     * GNU patch zero-count convention: @@ -N,0 +N,M @@ inserts M
     * new lines at old position N.  @@ -1,0 +1,2 @@ inserts 2 lines
     * after line 1 (before line 2).
     */
    public function testZeroCountInsertionHunkIsPreserved(): void
    {
        $targetPath = $this->tmpDir.'/zero_count_insert_target.txt';
        $original = "line 1\nline 2\n";
        file_put_contents($targetPath, $original);

        // Insert 2 lines after line 1 (before line 2): @@ -1,0 +1,2 @@
        // actualOld=0, actualNew=2, declaredOld=0, declaredNew=2
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -1,0 +1,2 @@
+INSERTED A
+INSERTED B
DIFF;

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);

        $expected = "line 1\nINSERTED A\nINSERTED B\nline 2\n";
        $this->assertSame($expected, file_get_contents($targetPath));
    }

    /**
     * Zero-count deletion hunk must also pass through uncorrupted.
     */
    public function testZeroCountDeletionHunkIsPreserved(): void
    {
        $targetPath = $this->tmpDir.'/zero_count_delete_target.txt';
        $original = "keep\nremove1\nremove2\nkeep\n";
        file_put_contents($targetPath, $original);

        // Remove 2 lines starting at line 2: @@ -2,2 +2,0 @@
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -2,2 +2,0 @@
-remove1
-remove2
DIFF;

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);

        $expected = "keep\nkeep\n";
        $this->assertSame($expected, file_get_contents($targetPath));
    }

    /**
     * Truncation heuristic must reject when both sides under-shoot even
     * when the TOTAL body line count equals the declared per-side count
     * (N === declared).  This is the boundary where a naive blank-body
     * strip might have been tempted to match blank context lines — the
     * test proves the strip produces malformed rejection regardless.
     */
    public function testTruncatedHunkWithEqualBodyLineCountRejected(): void
    {
        $targetPath = $this->tmpDir.'/equal_body_trunc_target.txt';
        $original = "line1\nline2\nline3\n";
        file_put_contents($targetPath, $original);

        // Boundary case where declared counts (3,3) exceed actual side
        // counts (2,2) but the body line count (3) equals the declared
        // count.  Body: 1 context + 1 removal + 1 addition = 3 lines.
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -1,3 +1,3 @@
 line1
-line2
+CHANGED2
DIFF;

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException for boundary truncated hunk');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();

            // Must be classified as E_PATCH_FORMAT (not stale, not success)
            $this->assertStringContainsString('[E_PATCH_FORMAT]', $message);
            $this->assertTrue($e->retryable());

            // File must be completely unchanged
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

    public function testContextTruncationMarkedWhenOutputCapped(): void
    {
        $targetPath = $this->tmpDir.'/many_failures.txt';

        // File with 200 lines; create 8 mismatched hunks (each with
        // 4-context-line windows = 9 lines each). Merged non-overlapping
        // context totals 72 lines, exceeding the 60-line cap, so the
        // final hunk(s) will be truncated and a marker emitted.
        $lines = [];
        for ($i = 1; $i <= 200; ++$i) {
            $lines[] = \sprintf('line %03d data', $i);
        }

        $original = implode("\n", $lines)."\n";
        file_put_contents($targetPath, $original);

        // 8 hunks at lines 5, 20, 35, 50, 65, 80, 95, 110
        $hunks = '';
        foreach ([5, 20, 35, 50, 65, 80, 95, 110] as $lineno) {
            $hunks .= \sprintf(
                "@@ -%d,5 +%d,5 @@\n line %03d data\n line %03d data\n-removed at %d\n+added at %d\n line %03d data\n line %03d data\n",
                $lineno, $lineno, $lineno, $lineno + 1, $lineno, $lineno, $lineno + 3, $lineno + 4,
            );
        }

        $patch = "--- f\n+++ f\n".$hunks;

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString('E_PATCH_STALE', $message);

            // Truncation marker should appear when lines are omitted
            $this->assertStringContainsString('truncated', $message);

            // First hunk context should be present
            $this->assertStringContainsString('line 005 data', $message);

            // Original must be untouched
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

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

            // The write should fail — expect a write code (no rollback
            // needed since no bytes were written).
            $this->assertMatchesRegularExpression('/E_PATCH_WRITE/', $message);
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
