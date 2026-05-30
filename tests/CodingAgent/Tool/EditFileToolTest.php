<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\CodingAgent\Tool\EditFileTool;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Tool\EditFileTool
 * @covers \Ineersa\CodingAgent\Tool\ToolDefinitionDTO
 */
final class EditFileToolTest extends TestCase
{
    private StackToolExecutionContextAccessor $contextAccessor;
    private ToolRuntime $toolRuntime;
    private string $tmpDir;
    private EditFileTool $editFileTool;

    protected function setUp(): void
    {
        $this->contextAccessor = new StackToolExecutionContextAccessor();
        $this->toolRuntime = new ToolRuntime($this->contextAccessor);

        $this->tmpDir = sys_get_temp_dir().'/hatfield_edit_test_'.bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0750, recursive: true);

        $this->editFileTool = new EditFileTool($this->toolRuntime);
    }

    protected function tearDown(): void
    {
        $this->rmDir($this->tmpDir);
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
        $this->assertSame($modified, file_get_contents($targetPath));
    }

    public function testEditReturnsNoChangesMessageForIdenticalPatch(): void
    {
        $targetPath = $this->tmpDir.'/no_change.txt';
        $original = "a\nb\nc\n";
        file_put_contents($targetPath, $original);

        // Construct a patch that removes and re-adds the same content (net no-op).
        // diff -u on identical files returns empty, so we build the hunk manually:
        // change b to b — dry-run passes, apply produces same content.
        $patch = "--- a/file\n+++ b/file\n@@ -1,3 +1,3 @@\n a\n-b\n+b\n c\n";

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('No changes', $result);
        // File content must remain identical
        $this->assertSame($original, file_get_contents($targetPath));
    }

    /* ── __invoke() rejection tests ── */

    public function testEditBadPatchRejectedOriginalUnchanged(): void
    {
        $targetPath = $this->tmpDir.'/bad_patch.txt';
        $original = "hello\nworld\n";
        file_put_contents($targetPath, $original);

        // Create a valid diff against completely different content so it
        // fails to match the target file during dry-run.
        $patchOld = "something\ncompletely\ndifferent\n";
        $patchNew = "something\nnew\ndifferent\n";
        $patch = $this->createUnifiedDiff($patchOld, $patchNew);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Patch dry-run failed');

        ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        // Original must be untouched
        $this->assertSame($original, file_get_contents($targetPath));
    }

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

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not exist');
        $this->expectExceptionMessage('write tool');

        ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);
    }

    /* ── __invoke() argument validation tests ── */

    public function testEditThrowsOnMissingPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"path" argument is required');

        ($this->editFileTool)(['patch' => 'some diff content']);
    }

    public function testEditThrowsOnEmptyPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"path" argument is required');

        ($this->editFileTool)(['path' => '', 'patch' => 'diff']);
    }

    public function testEditThrowsOnNonStringPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"path" argument is required');

        ($this->editFileTool)(['path' => 42, 'patch' => 'diff']);
    }

    public function testEditThrowsOnMissingPatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"patch" argument is required');

        ($this->editFileTool)(['path' => $this->tmpDir.'/test.txt']);
    }

    public function testEditThrowsOnNonStringPatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('"patch" argument is required');

        ($this->editFileTool)(['path' => $this->tmpDir.'/test.txt', 'patch' => ['not', 'a', 'string']]);
    }

    /* ── Whitespace tolerance test ── */

    public function testEditWhitespaceTolerantMatch(): void
    {
        $targetPath = $this->tmpDir.'/whitespace.txt';
        // Original has 4 spaces between words
        $original = "apple    banana    cherry\n";
        file_put_contents($targetPath, $original);

        // Patch expects 1 space between words (uses -l flag to tolerate whitespace)
        $patchOld = "apple banana cherry\n";
        $patchNew = "apple banana date\n";
        $patch = $this->createUnifiedDiff($patchOld, $patchNew);

        $result = ($this->editFileTool)(['path' => $targetPath, 'patch' => $patch]);

        $this->assertStringContainsString('Applied patch', $result);
        // With -l, patch produces content matching the patch's whitespace
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

        // The file should NOT have been modified
        $this->assertSame($originalContent, file_get_contents($targetPath));
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
                // Identical content — return a valid empty diff representation
                return '';
            }

            // Strip the absolute path prefixes from the ---/+++ lines so the
            // patch is cleaner, though patch itself ignores header paths.
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

    private function rmDir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir()
                ? rmdir((string) $item)
                : unlink((string) $item);
        }

        @rmdir($path);
    }
}
