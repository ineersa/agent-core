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

        // Tool description must lead with the plain-@@ patch shape, not
        // generic unified-diff wording that anchors models on numbered hunks.
        $this->assertStringContainsString('plain-@@ patch', strtolower($definition->description));
        $this->assertStringContainsString('plain-@@ patch', strtolower($definition->promptLine));
        $this->assertStringNotContainsString('unified diff patch', strtolower($definition->description));
        $this->assertStringNotContainsString('unified diff patch', strtolower($definition->promptLine));

        // Guidelines must use plain @@, mention hunk header resolution
        $guidelinesText = implode(' ', $definition->promptGuidelines);
        $this->assertStringContainsString('read', strtolower($guidelinesText));
        // Must NOT mention cat -n (model has read tool with line numbers)
        $this->assertStringNotContainsString('cat -n', $guidelinesText);
        $this->assertStringContainsString('plain `@@`', $guidelinesText);
        // Must NOT show numbered header example (model should not anchor on it)
        $this->assertStringNotContainsString('@@ -42', $guidelinesText);

        // Guidelines must warn against common LLM mistakes observed in smoke testing
        $this->assertStringContainsString('markdown code fences', strtolower($guidelinesText));
        $this->assertStringContainsString('end new file', strtolower($guidelinesText));
        $this->assertStringContainsString('trailing newline', strtolower($guidelinesText));
        // Guidelines must mention the tool resolves/computes hunk headers
        $this->assertStringContainsString('resolves', strtolower($guidelinesText));
        $this->assertStringContainsString('computes', strtolower($guidelinesText));

        // Guidelines must include generic diff-marker examples for file
        // content that itself starts with '-' or '+' (session-5 regression).
        $this->assertStringContainsString('-foo', $guidelinesText);
        $this->assertStringContainsString('--foo', $guidelinesText);
        $this->assertStringContainsString('+-foo', $guidelinesText);

        // Guidelines must instruct re-reading when success stats contradict intent
        $this->assertStringContainsString('contradict', strtolower($guidelinesText));
        $this->assertStringContainsString('re-read', strtolower($guidelinesText));

        // Guidelines must instruct using both offset and limit for targeted reads
        $this->assertStringContainsString('both `offset` and `limit`', $guidelinesText);
        // Guidelines must tell model not to re-read full file on same-file follow-ups
        $this->assertStringContainsString('do not re-read the full file', strtolower($guidelinesText));

        // Guidelines must include concrete plain-@@ patch template
        $this->assertStringContainsString('Minimal patch template', $guidelinesText);
        $this->assertStringContainsString('-old line', $guidelinesText);
        $this->assertStringContainsString('+new line', $guidelinesText);

        // Guidelines must say plain @@ means no line numbers needed
        $this->assertStringContainsString('do not need line numbers', strtolower($guidelinesText));

        // Guidelines must include explicit line-change `-old`/`+new` pair guidance
        $this->assertStringContainsString('NEVER be modified', $guidelinesText);

        // Guidelines must NOT include "file is small" full-read permission
        $this->assertStringNotContainsString('file is small', strtolower($guidelinesText));
    }

    public function testDefinitionHasRetryGuidelines(): void
    {
        $definition = $this->editFileTool->definition();
        $guidelinesText = implode(' ', $definition->promptGuidelines);

        // Guidelines must mention read tool and reading behavior
        $this->assertStringContainsString('`read`', $guidelinesText);
        $this->assertStringContainsString('retry', strtolower($guidelinesText));
        $this->assertStringContainsString('trailing newline', strtolower($guidelinesText));
        $this->assertStringContainsString('current context', strtolower($guidelinesText));
        // Must NOT suggest full-file reads unconditionally before every edit
        $this->assertStringContainsString('do not re-read the whole file', strtolower($guidelinesText));
        $this->assertStringContainsString('offset', strtolower($guidelinesText));
        // Must also mention limit for targeted reads (offset+limit together)
        $this->assertStringContainsString('limit', strtolower($guidelinesText));
        // Must warn against numbered headers for self-written patches
        $this->assertStringContainsString('never calculate or write numbered headers', strtolower($guidelinesText));
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

    public function testSuccessChangedContextMarksOnlyAdditionLines(): void
    {
        $targetPath = $this->tmpDir.'/changed_markers.txt';
        // 5-line file: lines 1-5
        $original = "line1\nline2\nline3\nline4\nline5\n";
        file_put_contents($targetPath, $original);

        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -1,5 +1,6 @@
 line1
 line2
+INSERTED
 line3
-line4
+CHANGED4
 line5
+APPEND
DIFF;

        $expected = "line1\nline2\nINSERTED\nline3\nCHANGED4\nline5\nAPPEND\n";

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        $this->assertSame($expected, file_get_contents($targetPath));

        // Success output must include Updated file context chunks
        $this->assertStringContainsString('Updated file context', $result);

        // The added/replacement lines (INSERTED, CHANGED4, APPEND) must be
        // marked with →, but context lines (line1, line2, line3, line5)
        // must NOT be marked.
        // Check that context lines are present WITHOUT the → marker.
        $this->assertMatchesRegularExpression('/^ (?!→)\s+\d+: line1$/m', $result);
        $this->assertMatchesRegularExpression('/^ (?!→)\s+\d+: line2$/m', $result);
        $this->assertMatchesRegularExpression('/^ (?!→)\s+\d+: line3$/m', $result);
        $this->assertMatchesRegularExpression('/^ (?!→)\s+\d+: line5$/m', $result);

        // Check that added lines ARE marked with →
        $this->assertMatchesRegularExpression('/^→\s+\d+: INSERTED$/m', $result);
        $this->assertMatchesRegularExpression('/^→\s+\d+: CHANGED4$/m', $result);
        $this->assertMatchesRegularExpression('/^→\s+\d+: APPEND$/m', $result);

        // No full diff echo
        $this->assertStringNotContainsString('@@', $result);
    }

    /**
     * When a hunk header's old-start is offset from the actual match
     * position (GNU patch -F3 fuzz), success output must mark the
     * actual added/replacement lines — not the lines implied by the
     * declared header numbers.
     *
     * Here the file has "X\na\nb\nc\nd\n" and the patch declares
     *
     * @@ -1,3 +1,3 @@ for the block "a\nb\nc", but that block only
     * matches at positions 2-4 (after "X").  GNU patch applies with
     * an offset of 1.  The arrows must mark the actual patched-file
     * positions (3 and 5), not positions 2 and 4 derived from the
     * declared +1 start.
     */
    public function testSuccessChangedContextHandlesOffsetFromDeclaredHeader(): void
    {
        $targetPath = $this->tmpDir.'/offset_markers.txt';
        // File: line1 = "X" (anchor that forces offset)
        //       lines 2-5 = a, b, c, d
        $original = "X\na\nb\nc\nd\n";
        file_put_contents($targetPath, $original);

        // Patch declares @@ -1,3 +1,3 @@ but the old-side block
        // (a\nb\nc) only appears at positions 2-4.  The declared
        // old-start (1) is wrong by 1 line.
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -1,3 +1,3 @@
 a
+INSERTED
 b
-c
+CHANGED
DIFF;

        // Expected result after patch applied at match position 2:
        // X, a, INSERTED, b, CHANGED, d
        $expected = "X\na\nINSERTED\nb\nCHANGED\nd\n";

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        $this->assertSame($expected, file_get_contents($targetPath));

        // Context lines (a, b, d) must NOT be marked with →
        $this->assertMatchesRegularExpression('/^ (?!→)\s+\d+: a$/m', $result);
        $this->assertMatchesRegularExpression('/^ (?!→)\s+\d+: b$/m', $result);
        $this->assertMatchesRegularExpression('/^ (?!→)\s+\d+: d$/m', $result);

        // Actual addition lines (INSERTED at line 3, CHANGED at line 5)
        // must be marked with → at their real positions.
        $this->assertMatchesRegularExpression('/^→\s+3: INSERTED$/m', $result);
        $this->assertMatchesRegularExpression('/^→\s+5: CHANGED$/m', $result);

        // The context line 'a' at position 2 must NOT get a → marker
        // (it is context, not an addition).
        $this->assertStringNotContainsString('→  2:', $result);
    }

    /**
     * Cross-check: trailing markdown-fence stripping must not corrupt
     * arrow placement regardless of whether the header is offset.
     *
     * File has 8 lines, patch adds one line with a trailing ``` that
     * the normalizer strips.  Arrows must mark the actual addition.
     */
    public function testSuccessChangedContextNotCorruptedByTrailingFenceNormalization(): void
    {
        $targetPath = $this->tmpDir.'/trailing_fence.txt';

        // 8-line file: lines 1-8, each ending with \n
        $original = "a\nb\nc\nd\ne\nf\ng\nh\n";
        file_put_contents($targetPath, $original);

        // Patch adds 'INSERTED' after line 2, with trailing ``` fence.
        // Header @@ -1,3 +1,4 @@ is correct for b,c context + INSERTED.
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -1,3 +1,4 @@
 a
 b
+INSERTED
 c
 d
```
DIFF;

        // After applying: a, b, INSERTED, c, d, e, f, g, h
        $expected = "a\nb\nINSERTED\nc\nd\ne\nf\ng\nh\n";

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        $this->assertSame($expected, file_get_contents($targetPath));

        // INSERTED at line 3 must be marked with →
        $this->assertMatchesRegularExpression('/^→\s+3: INSERTED$/m', $result);

        // Context lines 'a', 'b', 'c', 'd' must NOT carry →
        $this->assertMatchesRegularExpression('/^ (?!→)\s+1: a$/m', $result);
        $this->assertMatchesRegularExpression('/^ (?!→)\s+2: b$/m', $result);
        $this->assertMatchesRegularExpression('/^ (?!→)\s+4: c$/m', $result);

        // No Hunk succeeded / fuzz / offset / dev/null leaked into success output
        $this->assertStringNotContainsString('Hunk succeeded', $result);
        $this->assertStringNotContainsString('fuzz', $result);
        $this->assertStringNotContainsString('offset', $result);
        $this->assertStringNotContainsString('/dev/null', $result);
    }

    /**
     * When the old-side block appears multiple times in the file and a
     * numbered hunk header declares oldStart near the later occurrence,
     * success context arrows must mark the lines at the later (nearest)
     * match — not the first occurrence.
     *
     * File has duplicate "hello\nworld" blocks at lines 2-3 and 5-6.
     * The hunk declares oldStart=5, targeting the later block.  Arrows
     * must appear around line 6 (the added line after the second
     * block), not around line 3.
     */
    public function testSuccessChangedContextUsesNearestMatchWhenDuplicateBlocksExist(): void
    {
        $targetPath = $this->tmpDir.'/dup_markers.txt';

        // "hello\nworld" appears twice: lines 2-3 and 5-6.
        $original = "unique_a\nhello\nworld\nunique_b\nhello\nworld\nunique_c\n";
        file_put_contents($targetPath, $original);

        // Declared oldStart=5 targets the second occurrence.
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -5,2 +5,3 @@
 hello
 world
+INSERTED
DIFF;

        // After: unique_a, hello, world, unique_b, hello, world, INSERTED, unique_c
        $expected = "unique_a\nhello\nworld\nunique_b\nhello\nworld\nINSERTED\nunique_c\n";

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        $this->assertSame($expected, file_get_contents($targetPath));

        // INSERTED must be at line 7 (after second world), NOT at line 4
        $this->assertMatchesRegularExpression('/^→\s+7: INSERTED$/m', $result);

        // Context lines around the second block must NOT carry →
        $this->assertMatchesRegularExpression('/^ (?!→)\s+4: unique_b$/m', $result);
        $this->assertMatchesRegularExpression('/^ (?!→)\s+5: hello$/m', $result);
        $this->assertMatchesRegularExpression('/^ (?!→)\s+6: world$/m', $result);
        $this->assertMatchesRegularExpression('/^ (?!→)\s+8: unique_c$/m', $result);

        // No Hunk succeeded / fuzz / offset / dev/null leaked into success output
        $this->assertStringNotContainsString('Hunk succeeded', $result);
        $this->assertStringNotContainsString('fuzz', $result);
        $this->assertStringNotContainsString('offset', $result);
        $this->assertStringNotContainsString('/dev/null', $result);
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
     * unambiguously state that no changes were applied and NOT
     * include confusing GNU patch partial-success diagnostics
     * (Hunk succeeded, offset, fuzz) — session-5/7 regression.
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
            $this->assertStringContainsString('This edit attempt failed', $message);
            $this->assertStringContainsString('target file is untouched', $message);

            // Must NOT include confusing partial-success diagnostics
            $this->assertStringNotContainsString('Hunk succeeded', $message);
            $this->assertStringNotContainsString('diagnostics only', $message);
            $this->assertStringNotContainsString('offset', strtolower($message));
            $this->assertStringNotContainsString('fuzz', strtolower($message));

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

            // The hint should advise targeted read with offset/limit, not broad full-file re-read
            $hint = $e->hint() ?? '';
            $this->assertStringContainsString('read', strtolower($hint));
            $this->assertStringContainsString('offset', strtolower($hint));
            $this->assertStringContainsString('limit', strtolower($hint));
            $this->assertStringNotContainsString('cat -n', $hint);
            // Hint must NOT include "file is small" full-read permission
            $this->assertStringNotContainsString('file is small', strtolower($hint));

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
            $this->assertStringContainsString('plain-@@ patch', strtolower($e->hint() ?? ''));

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

    /**
     * Session-8 regression: a valid unified diff followed by a stray pure
     * ``` fence line and prose / pseudo tool-call text must be normalized
     * safely — the fence and everything after it are stripped and the
     * valid diff portion applies correctly.
     */
    public function testSession8TrailingFenceAndProseStripped(): void
    {
        $targetPath = $this->tmpDir.'/session8_target.txt';
        $original = "line A\nline B\nline C\n";
        file_put_contents($targetPath, $original);

        // Valid unified diff followed by a pure ``` line and prose / pseudo
        // tool-call text — exactly the pattern observed in session 8.
        $patch = <<<'PATCH'
--- a/file
+++ b/file
@@ -1,3 +1,3 @@
 line A
-line B
+CHANGED B
 line C
```
Wait, the hunk header format is wrong...
<function=edit>
<parameter=path>wrong</parameter>
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

            // Hint must specifically diagnose truncation and recommend
            // exactly @@ as the hunk header; must also include declared-vs-actual
            // count details.
            $this->assertStringContainsString('truncated', strtolower($hint));
            $this->assertStringContainsString('`@@`', $hint);
            $this->assertStringContainsString('do not use numbered headers', $hint);
            $this->assertStringContainsString('Numbered hunk header declared 4 old / 4 new lines', $hint);
            $this->assertStringContainsString('2 old / 2 new lines', $hint);
            $this->assertStringNotContainsString('cat -n', $hint);

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

    /**
     * Session-10 regression: a numbered hunk with inflated counts must
     * fail closed with E_PATCH_FORMAT and clear declared-vs-actual
     * messaging — never silently auto-repair (auto-repair can partial-apply
     * genuinely truncated patches whose body ends on a context line).
     * The model must retry with plain @@ instead.
     */
    public function testNumberedHunkOverDeclaredCountsRejectedAsFormatWithDeclaredCountHint(): void
    {
        // Recreate session-10 DummyService.php structure (simplified).
        $targetPath = $this->tmpDir.'/numbered_overdeclared_target.txt';
        $original = <<<'PHP'
final class DummyService
{
    private const DEFAULT_LIMIT = 25;

    public function summarize(array $items): string
    {
        $lines = [];

        foreach ($items as $index => $item) {
            $trimmed = trim((string) $item);
            if ($trimmed === '') {
                continue;
            }
            $lines[] = sprintf('- item %d: %s', $index + 1, $trimmed);
        }

        return implode("\n", $lines);
    }
}
PHP;
        file_put_contents($targetPath, $original);

        // Session-10 exact error pattern: numbered hunk declares
        // @@ -3,7 +3,7 @@ but body has only 5 old / 5 new lines.
        // (3 context + 1 removal + 1 addition).  Body has trailing
        // context after the +/- change.
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -3,7 +3,7 @@ final class DummyService
     private const DEFAULT_LIMIT = 25;

-    public function summarize(array $items): string
+    public function summarizeItems(array $items): string
     {
         $lines = [];
DIFF;

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException for over-declared numbered hunk');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();
            $hint = $e->hint() ?? '';

            // Must be classified as E_PATCH_FORMAT (fail closed, not auto-repaired).
            $this->assertStringContainsString('[E_PATCH_FORMAT]', $message);
            $this->assertTrue($e->retryable());

            // Hint must include the declared-vs-actual count details
            // and recommend plain @@ (not numbered headers).
            $this->assertStringContainsString('truncated', strtolower($hint));
            $this->assertStringContainsString('Numbered hunk header declared 7 old / 7 new lines', $hint);
            $this->assertStringContainsString('5 old / 5 new lines', $hint);
            $this->assertStringContainsString('`@@`', $hint);
            $this->assertStringContainsString('do not use numbered headers', $hint);
            $this->assertStringNotContainsString('cat -n', $hint);

            // File must be completely unchanged
            $actual = file_get_contents($targetPath);
            $this->assertSame($original, $actual);
            $this->assertStringNotContainsString('summarizeItems', $actual);
        }
    }

    /**
     * Safety test: a numbered hunk with both sides under-declared that
     * ends with a change line (+ or -) instead of trailing context must
     * still fail closed with E_PATCH_FORMAT.  Such a body is more likely
     * genuinely truncated than just miscounted.
     */
    public function testNumberedHunkEndingWithChangeLineStillFailsClosed(): void
    {
        $targetPath = $this->tmpDir.'/ending_with_change_target.txt';
        $original = "line 1\nline 2\nline 3\nline 4\n";
        file_put_contents($targetPath, $original);

        // Declared @@ -2,4 +2,4 @@ but body has only 3 lines and ends
        // with a + change line (no trailing context).  actualOld=2, actualNew=2.
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -2,4 +2,4 @@
 line 2
-line 3
+NEW LINE 3
DIFF;

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException for truncated hunk ending with change');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();
            $hint = $e->hint() ?? '';

            // Must be classified as E_PATCH_FORMAT (fail closed).
            $this->assertStringContainsString('[E_PATCH_FORMAT]', $message);
            $this->assertTrue($e->retryable());

            // Hint must include the declared-vs-actual count details
            // and instruct to use exactly @@ (not numbered headers).
            $this->assertStringContainsString('truncated', strtolower($hint));
            $this->assertStringContainsString('Numbered hunk header declared 4 old / 4 new lines', $hint);
            $this->assertStringContainsString('2 old / 2 new lines', $hint);
            $this->assertStringContainsString('exactly `@@`', $hint);
            $this->assertStringContainsString('do not use numbered headers', $hint);
            $this->assertStringNotContainsString('cat -n', $hint);

            // File must be completely unchanged
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

    /**
     * Reviewer regression: a both-sides-under-declared numbered hunk whose
     * body ends on a context line AND whose old-side block matches the file
     * must STILL fail closed — not be silently partially applied.
     *
     * Concrete: file = line1..line5, patch declares @@ -2,4 +2,4 @@
     * but body only has line2, -line3, +X, line4 (4 paren lines — 2 context,
     * 1 removal, 1 addition).  actualOld=3, actualNew=3.  The old-side
     * block [line2, line3, line4] matches the file at line 2.  This
     * must be rejected: a truncated generation that happens to land on
     * a context line is indistinguishable from an over-declared header,
     * and auto-repair would silently drop the intended line 5 → Y change.
     *
     * @group high
     */
    public function testTruncatedBothSidesUnderShotEndingOnContextLineStillFailsClosed(): void
    {
        $targetPath = $this->tmpDir.'/truncated_ends_on_context_target.txt';
        // 5-line file: lines 1–5
        $original = "line 1\nline 2\nline 3\nline 4\nline 5\n";
        file_put_contents($targetPath, $original);

        // Declared @@ -2,4 +2,4 @@ but body has only 4 paren lines:
        // actualOld=3, actualNew=3 — both under declared 4/4.
        // Last body line " line 4" is context — passes trailing-context
        // heuristic, but the patch is genuinely truncated (model intended
        // to also change line 5).
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -2,4 +2,4 @@
 line 2
-line 3
+X
 line 4
DIFF;

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException for truncated hunk ending on context line');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();
            $hint = $e->hint() ?? '';

            // Must be classified as E_PATCH_FORMAT — fail closed, NOT repaired.
            $this->assertStringContainsString('[E_PATCH_FORMAT]', $message);
            $this->assertTrue($e->retryable());

            // Hint must include the specific declared-vs-actual counts
            // and recommend plain @@ (not numbered headers).
            $this->assertStringContainsString('truncated', strtolower($hint));
            $this->assertStringContainsString('Numbered hunk header declared 4 old / 4 new lines', $hint);
            $this->assertStringContainsString('3 old / 3 new lines', $hint);
            $this->assertStringContainsString('`@@`', $hint);
            $this->assertStringContainsString('do not use numbered headers', $hint);
            $this->assertStringNotContainsString('cat -n', $hint);

            // File must be completely unchanged — no silent partial application!
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

    /* ── Relaxed hunk header (plain @@) tests ── */

    /**
     * A plain @@ hunk header without line numbers or counts must be
     * resolved against the current file and applied successfully.
     */
    public function testRelaxedHunkAppliesAgainstCurrentFile(): void
    {
        $targetPath = $this->tmpDir.'/relaxed_hunk_target.txt';
        $original = "line1\nline2\nline3\nline4\n";
        file_put_contents($targetPath, $original);

        // Relaxed hunk: plain @@, body has context + removal + addition
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@
 line1
-line2
+CHANGED2
 line3
DIFF;

        $expected = "line1\nCHANGED2\nline3\nline4\n";

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        $this->assertStringNotContainsString('@@', $result);
        $this->assertSame($expected, file_get_contents($targetPath));
    }

    /**
     * Multiple plain @@ hunks in one patch must all be resolved,
     * ordered, and applied — the tool computes shifted offsets.
     */
    public function testMultiHunkRelaxedEditAppliesWithComputedOffsets(): void
    {
        $targetPath = $this->tmpDir.'/multi_relaxed_target.txt';
        $original = "a\nb\nc\nd\ne\nf\n";
        file_put_contents($targetPath, $original);

        // First relaxed hunk: insert NEW after line 1 (a→a,NEW).
        // old block = ["a","b"] (context around insertion point), new block = ["a","NEW","b"].
        // delta = +1.  Second relaxed hunk at original line 5 (e→E):
        // after insertion, e is at line 6.  Tool must compute shifted newStart.
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@
 a
+NEW
 b
 c
@@
 d
-e
+E
 f
DIFF;

        $expected = "a\nNEW\nb\nc\nd\nE\nf\n";

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        $this->assertSame($expected, file_get_contents($targetPath));
    }

    /**
     * A relaxed hunk whose old-side block appears multiple times in the
     * file must fail with a clear ambiguity hint.
     */
    public function testDuplicateOldBlockInRelaxedHunkFailsWithAmbiguityHint(): void
    {
        $targetPath = $this->tmpDir.'/duplicate_relaxed_target.txt';
        // File with repeated block at lines 2-4 and 5-7
        $original = "line1\nmarker\nrepeated\ncontext\nmarker\nrepeated\ncontext\n";
        file_put_contents($targetPath, $original);

        // Relaxed hunk whose old block matches twice
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@
 marker
-repeated
+changed
 context
DIFF;

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException for duplicate match');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString('[E_PATCH_FORMAT]', $message);
            $this->assertStringContainsString('no changes were applied', strtolower($message));
            $this->assertStringContainsString('matches', strtolower($message));
            $this->assertStringContainsString('locations', strtolower($message));

            $hint = $e->hint() ?? '';
            $this->assertStringContainsString('more unchanged context', strtolower($hint));
            $this->assertStringContainsString('unique', strtolower($hint));

            $this->assertTrue($e->retryable());

            // File must be untouched
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

    /**
     * A relaxed hunk whose old-side block does NOT match any location
     * in the file must fail as E_PATCH_STALE with no changes applied.
     */
    public function testUnmatchedRelaxedHunkFailsAsStale(): void
    {
        $targetPath = $this->tmpDir.'/unmatched_relaxed_target.txt';
        $original = "hello\nworld\n";
        file_put_contents($targetPath, $original);

        // Relaxed hunk with context that is NOT in the file
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@
 not
-present
+modified
DIFF;

        try {
            ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
            $this->fail('Expected ToolCallException for unmatched relaxed hunk');
        } catch (ToolCallException $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString('[E_PATCH_STALE]', $message);
            $this->assertStringContainsString('no changes were applied', strtolower($message));
            $this->assertStringContainsString('was not found', $message);

            $hint = $e->hint() ?? '';
            $this->assertStringContainsString('stale', strtolower($hint));
            // Hint must advise targeted read (not broad re-read)
            $this->assertStringContainsString('`read`', $hint);
            $this->assertStringContainsString('offset', strtolower($hint));
            // Hint must NOT include "file is small" full-read permission
            $this->assertStringNotContainsString('file is small', strtolower($hint));
            // Hint must diagnose changed-line-as-context mistake (session-15 regression)
            $this->assertStringContainsString('changed line as context', strtolower($hint));
            $this->assertStringContainsString('context lines must match', strtolower($hint));

            $this->assertTrue($e->retryable());

            // File must be untouched
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

    /**
     * A standard counted hunk must still work alongside relaxed hunks.
     */
    public function testMixedStandardAndRelaxedHunksApplyTogether(): void
    {
        $targetPath = $this->tmpDir.'/mixed_hunk_target.txt';
        $original = "alpha\nbeta\ngamma\n";
        file_put_contents($targetPath, $original);

        // Standard hunk: replace alpha→ALPHA at line 1
        // Relaxed hunk: delete gamma (context "beta", removal "-gamma")
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -1,1 +1,1 @@
-alpha
+ALPHA
@@
 beta
-gamma
DIFF;

        $expected = "ALPHA\nbeta\n";

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        $this->assertSame($expected, file_get_contents($targetPath));
    }

    /**
     * A miscounted standard hunk before a relaxed hunk must not falsely
     * report overlap.  The tool should use actual body counts, not declared
     * header counts, for ordering/overlap validation and cumulative delta.
     *
     * Regresion: resolveRelaxedHunks() used declared counts (e.g. old=5
     * from @@ -1,5 +1,1 @@) for validation before repairHunkCounts() could
     * fix the miscount.  A declared old=5 with body having only 1 old line
     * falsely claimed oldEnd=5, causing overlap with a relaxed hunk at old
     * line 4.
     */
    public function testMiscountedStandardHunkBeforeRelaxedHunkDoesNotFalselyOverlap(): void
    {
        $targetPath = $this->tmpDir.'/miscounted_std_before_relaxed.txt';
        $original = "a\nb\nc\nd\ne\nf\n";
        file_put_contents($targetPath, $original);

        // Standard hunk at line 1: declares old=5,new=1 but body has only
        // 1 old line and 1 new line (common LLM miscount).  Before fix,
        // this claimed old lines 1-5 and rejected the relaxed hunk at line 4.
        $patch = <<<'DIFF'
--- a/file
+++ b/file
@@ -1,5 +1,1 @@
-a
+A
@@
 d
-e
+E
 f
DIFF;

        $expected = "A\nb\nc\nd\nE\nf\n";

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        $this->assertSame($expected, file_get_contents($targetPath));
    }

    /* ── Relaxed hunk definition / guideline tests ── */

    public function testDefinitionGuidelinesMentionOptionalHunkCounts(): void
    {
        $definition = $this->editFileTool->definition();
        $guidelinesText = implode(' ', $definition->promptGuidelines);

        // Guidelines must mention that hunk counts/line numbers are optional
        // and plain @@ is the default (not "when unsure" fallback)
        $this->assertStringContainsString('plain', strtolower($guidelinesText));
        $this->assertStringContainsString('@@', $guidelinesText);
        $this->assertStringContainsString('as the default', strtolower($guidelinesText));
        $this->assertStringContainsString('resolves', strtolower($guidelinesText));
        $this->assertStringContainsString('without line numbers', strtolower($guidelinesText));
        // Must NOT have contradictory "when unsure" language
        $this->assertStringNotContainsString('when unsure', strtolower($guidelinesText));
        // Must NOT show numbered header example
        $this->assertStringNotContainsString('@@ -42', $guidelinesText);
        // Must include concrete template (not just abstract mention)
        $this->assertStringContainsString('-old line', $guidelinesText);
        $this->assertStringContainsString('+new line', $guidelinesText);
        // Must explicitly say no line numbers needed
        $this->assertStringContainsString('do not need line numbers', strtolower($guidelinesText));
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
