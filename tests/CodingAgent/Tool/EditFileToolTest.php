<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\Tool\Support\InodeLockHolder;
use Ineersa\CodingAgent\Tests\Tool\Support\ToolValidationHarness;
use Ineersa\CodingAgent\Tool\Arguments\EditFileArgumentsDTO;
use Ineersa\CodingAgent\Tool\Edit\EditLockDiagnosticsService;
use Ineersa\CodingAgent\Tool\Edit\PatchApplier;
use Ineersa\CodingAgent\Tool\Edit\PatchFailureFormatter;
use Ineersa\CodingAgent\Tool\EditFileTool;
use Ineersa\CodingAgent\Tool\ToolRuntime;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Contract\JsonSchema\Attribute\Schema;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Clock\MockClock;

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
    private MockClock $clock;
    private TestLogger $logger;
    private string $tmpDir;
    private EditFileTool $editFileTool;

    protected function setUp(): void
    {
        $this->contextAccessor = new StackToolExecutionContextAccessor();
        $this->toolRuntime = new ToolRuntime($this->contextAccessor);
        $this->clock = new MockClock();
        $this->logger = new TestLogger();
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield_edit_test');
        $this->editFileTool = new EditFileTool($this->toolRuntime, new PatchApplier($this->clock, new EditLockDiagnosticsService($this->logger, $this->contextAccessor), new PatchFailureFormatter()));
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testDefinitionPromptMentionsHunkFormatGuidance(): void
    {
        $definition = $this->editFileTool->definition();

        $this->assertStringContainsString('@@ hunks', $definition->description);
        $this->assertStringNotContainsString('Codex-style', $definition->description);
        $this->assertStringNotContainsString('Codex', $definition->description);
        $this->assertStringContainsString('diff prefix', $definition->description);
        $this->assertStringContainsString('leading space', $definition->description);

        // The crafted patch description now lives as a #[Schema] attribute on
        // EditFileArgumentsDTO (native schema generation source of truth).
        $patchProperty = new \ReflectionProperty(EditFileArgumentsDTO::class, 'patch');
        $patchSchema = $patchProperty->getAttributes(Schema::class)[0]->newInstance()->description ?? '';
        $this->assertStringNotContainsString('Codex-style', $patchSchema);
        $this->assertStringNotContainsString('Codex', $patchSchema);
        $this->assertStringContainsString('@@', $patchSchema);
        $this->assertStringContainsString('space for unchanged context', $patchSchema);
        $this->assertStringContainsString('sequential, non-overlapping', strtolower($patchSchema));
        $this->assertStringContainsString('do not append `*** End Patch`', $patchSchema);

        $guidelines = implode(' ', $definition->promptGuidelines);
        $this->assertStringNotContainsString('Codex-style', $guidelines);
        $this->assertStringNotContainsString('Codex', $guidelines);
        $this->assertStringContainsString('@@', $guidelines);
        $this->assertStringContainsString('diff prefix', $guidelines);
        $this->assertStringContainsString('Empty physical lines inside a hunk', $guidelines);
        $this->assertStringContainsString('literal source-text anchors', $guidelines);
        $this->assertStringContainsString('JSON example', $guidelines);
        $this->assertStringContainsString('"patch":"@@\\n unchanged context\\n-old line\\n+new line"', $guidelines);
        $this->assertStringContainsString('do not append `*** End Patch`', $guidelines);
        $this->assertStringContainsString('no ---/+++', strtolower($guidelines));
        $this->assertStringNotContainsString('cat -n', $guidelines);
        $this->assertStringContainsString('numbered @@', strtolower($guidelines));
        $this->assertStringContainsString('sequential, non-overlapping', strtolower($guidelines));
        $this->assertStringContainsString('physical end of the file', strtolower($guidelines));
        $this->assertStringContainsString('unique forward match', strtolower($guidelines));
        $this->assertStringNotContainsString('omit it for mid-file', strtolower($guidelines));
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

        $result = ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
        $this->assertSame(
            'Applied patch to '.$targetPath." (1 addition, 1 deletion)\n\nUpdated file context:\n→    1: line1\n→    2: LINE2\n→    3: line3\n",
            $result,
        );
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

        ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
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

        $result = ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
        $this->assertSame('No changes (patch produced identical content)', $result);
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
            ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertSame(
                "This edit attempt failed. No changes from this attempt were applied; the target file is untouched.\n\n[E_PATCH_STALE] Hunk #1 context not found in file.\n\nCurrent file context:\n→    1: alpha\n     2: beta\n     3: gamma\n",
                $e->getMessage(),
            );
            $this->assertSame('Could not locate: "missing\\nold". Seek hints are literal source-text anchors, not line numbers. Use read with offset/limit around line 1, then regenerate the patch with exact current context lines (or omit the seek hint).', $e->hint());
            $this->assertTrue($e->retryable());
            $this->assertSame("alpha\nbeta\ngamma\n", file_get_contents($targetPath));
        }
    }

    #[DataProvider('laterStaleHunks')]
    public function testLaterStaleHunkPreservesExactFailureResponse(string $secondHunk, string $needle): void
    {
        $targetPath = $this->tmpDir.'/later-stale.txt';
        $original = "one\ntwo\nthree\nfour\nfive\nsix\nseven\neight\nnine\nten\neleven\ntwelve\n";
        file_put_contents($targetPath, $original);

        try {
            ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: "@@\n eight\n-nine\n+NINE\n".$secondHunk));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertSame(
                "This edit attempt failed. No changes from this attempt were applied; the target file is untouched.\n\n[E_PATCH_STALE] Hunk #2 context not found in file.\n\nCurrent file context:\n     6: six\n     7: seven\n     8: eight\n     9: nine\n→   10: ten\n    11: eleven\n    12: twelve\n",
                $e->getMessage(),
            );
            $this->assertSame('Could not locate: "'.$needle.'". Each plain or seek-hinted `@@` starts a new sequential, non-overlapping hunk applied after earlier hunks; overlapping changes must be combined into one hunk. Seek hints are literal source-text anchors, not line numbers. Use read with offset/limit around line 10, then regenerate the patch with exact current context lines (or omit the seek hint).', $e->hint());
            $this->assertTrue($e->retryable());
            $this->assertSame($original, file_get_contents($targetPath));
        }
    }

    public static function laterStaleHunks(): iterable
    {
        yield 'missing old block' => ["@@\n-missing\n+new", 'missing'];
        yield 'missing seek hint' => ["@@ absent\n-ten\n+TEN", 'absent'];
    }

    public function testStaleUnicodeContextProducesSerializableErrorWithoutChangingFile(): void
    {
        $targetPath = $this->tmpDir.'/unicode-stale.txt';
        $original = "actual context\n";
        file_put_contents($targetPath, $original);
        $needle = '    // '.str_repeat('─', 46);

        try {
            ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: "@@\n ".$needle."\n-old\n+new"));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('E_PATCH_STALE', $e->getMessage());
            $this->assertStringContainsString('    // '.str_repeat('─', 23).'...', $e->hint());
            $this->assertJson(json_encode(['message' => $e->getMessage(), 'hint' => $e->hint()], \JSON_THROW_ON_ERROR));
            $this->assertSame($original, file_get_contents($targetPath));
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
            ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
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

        ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
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

        ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
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

        ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
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

        ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
        $this->assertStringContainsString("TARGET BLOCK\n", file_get_contents($targetPath));
    }

    public function testMalformedOldFormatRejected(): void
    {
        $targetPath = $this->tmpDir.'/format.txt';
        file_put_contents($targetPath, "x\n");

        $patch = "--- a/file\n+++ b/file\n@@ -1 +1 @@\n x\n";

        try {
            ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('E_PATCH_FORMAT', $e->getMessage());
            $this->assertSame("x\n", file_get_contents($targetPath));
        }
    }

    public function testEditMissingFileThrowsDirectingToWrite(): void
    {
        $targetPath = $this->tmpDir.'/missing.txt';

        // Runs through the native toolbox + ValidateToolCallArgumentsListener:
        // the EditFileTarget DTO constraint rejects the missing target before
        // the handler (and its lock-protected PatchApplier) ever runs.
        $toolbox = ToolValidationHarness::toolbox($this->editFileTool);
        $result = $toolbox->execute(new ToolCall('call-edit', 'edit', ['path' => $targetPath, 'patch' => "@@\n x\n"]));

        $message = (string) $result->getResult();
        $this->assertStringContainsString('does not exist or is not readable', $message);
        $this->assertStringContainsString('Use the write tool to create new files.', $message);
        $this->assertFileDoesNotExist($targetPath);
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
                ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: "@@\n a\n"));
            },
        );

        $this->assertSame($original, file_get_contents($targetPath));
    }

    public static function lockContentionCases(): iterable
    {
        yield 'target lock released' => [false];
        yield 'target waiter cancelled' => [true];
    }

    public function testInodeWaitExpiresWithDiagnosticsAndNeverWritesAfterRelease(): void
    {
        $path = $this->tmpDir.'/timeout.txt';
        file_put_contents($path, "private-original\n");
        $holder = new InodeLockHolder($path);
        $calls = 0;
        $token = $this->createStub(CancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturnCallback(function () use (&$calls): bool {
            // run(), pre-open, first lock attempt, then retry after real contention.
            if (4 === ++$calls) {
                $this->clock->modify('+5 seconds');
            }

            return false;
        });
        $arguments = new EditFileArgumentsDTO(path: $path, patch: "@@\n-private-original\n+private-replacement");
        try {
            try {
                $this->contextAccessor->with(new ToolContext('run', 7, 'edit-call', 'edit', $token, 1800, stepId: 'step'), fn () => ($this->editFileTool)($arguments));
                $this->fail('Expected bounded lock failure while the independent holder still owns the inode.');
            } catch (ToolCallException $e) {
                $this->assertStringContainsString('E_PATCH_LOCK_TIMEOUT', $e->getMessage());
                $this->assertFalse($e->retryable());
            }
            $this->assertSame(4, $calls);
            $this->assertSame("private-original\n", file_get_contents($path));
            $this->assertCount(1, $this->logger->records);
            $record = $this->logger->records[0];
            $this->assertSame('error', $record['level']);
            $this->assertSame('edit.inode_lock_timeout', $record['message']);
            $evidence = $record['context'];
            $this->assertSame('run', $evidence['run_id']);
            $this->assertSame('edit-call', $evidence['tool_call_id']);
            $this->assertSame('step', $evidence['step_id']);
            $this->assertSame($path, $evidence['target_path']);
            $this->assertSame(5.0, $evidence['wait_seconds']);
            $this->assertSame(1, $evidence['attempts']);
            $this->assertSame(stat($path)['ino'], $evidence['opened_file']['inode']);
            $this->assertFalse($evidence['path_identity_changed']);
            $this->assertStringNotContainsString('private-original', json_encode($evidence, \JSON_THROW_ON_ERROR));
            $this->assertStringNotContainsString('private-replacement', json_encode($evidence, \JSON_THROW_ON_ERROR));
            if ('Linux' === \PHP_OS_FAMILY && 'read' === $evidence['kernel_locks']['status']) {
                $this->assertContains($holder->pid, array_column($evidence['kernel_locks']['records'], 'pid'));
                $owner = array_find($evidence['processes'], static fn (array $process): bool => $process['pid'] === $holder->pid);
                $this->assertNotNull($owner);
                $this->assertNotEmpty($owner['matching_fds']);
                $waiter = array_find($evidence['processes'], static fn (array $process): bool => $process['pid'] === getmypid());
                $this->assertNotEmpty($waiter['matching_fds']);
                foreach ($waiter['matching_fds'] as $fd) {
                    $descriptor = '/proc/self/fd/'.$fd['fd'];
                    clearstatcache(true, $descriptor);
                    $this->assertFalse(file_exists($descriptor), 'Timed-out edit must close its descriptor before returning.');
                }
            }
        } finally {
            $holder->release();
        }
        $this->assertSame("private-original\n", file_get_contents($path), 'Expiry must not leave a pending edit that runs on lock release.');
        ($this->editFileTool)($arguments);
        $this->assertSame("private-replacement\n", file_get_contents($path));
    }

    public function testCancellationImmediatelyAfterInodeAcquisitionReleasesWithoutMutation(): void
    {
        $path = $this->tmpDir.'/cancel-after-acquire.txt';
        file_put_contents($path, "old\n");
        $calls = 0;
        $token = $this->createStub(CancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturnCallback(static function () use (&$calls): bool {
            return 4 === ++$calls; // run(), pre-open, pre-flock, post-flock.
        });
        try {
            $this->contextAccessor->with(new ToolContext('run', 1, 'call', 'edit', $token, 30), fn () => ($this->editFileTool)(new EditFileArgumentsDTO(path: $path, patch: "@@\n-old\n+new")));
            $this->fail('Expected post-acquisition cancellation.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cancelled', $e->getMessage());
        }
        $this->assertSame("old\n", file_get_contents($path));
        $this->assertSame([], $this->logger->records);
        ($this->editFileTool)(new EditFileArgumentsDTO(path: $path, patch: "@@\n-old\n+new"));
        $this->assertSame("new\n", file_get_contents($path));
    }

    #[DataProvider('lockContentionCases')]
    public function testEditLockContentionYieldsWithoutChangingFile(bool $cancel): void
    {
        $path = $this->tmpDir.'/contended.txt';
        file_put_contents($path, "old\n");
        $stream = fopen($path, 'r+b');
        $this->assertIsResource($stream);
        $this->assertTrue(flock($stream, \LOCK_EX | \LOCK_NB));
        $cancelled = false;
        $token = $this->createStub(CancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturnCallback(static function () use (&$cancelled): bool { return $cancelled; });
        $entered = new DeferredFuture();
        $waiting = \Amp\async(fn () => $this->contextAccessor->with(
            new ToolContext('run', 1, 'call', 'edit', $token, 30),
            function () use ($path, $entered): string {
                $entered->complete();

                return ($this->editFileTool)(new EditFileArgumentsDTO(path: $path, patch: "@@\n-old\n+new"));
            },
        ));
        try {
            $entered->getFuture()->await(new TimeoutCancellation(2));
            \Amp\async(static function () use ($waiting, $path): void {
                self::assertFalse($waiting->isComplete());
                self::assertSame("old\n", file_get_contents($path));
            })->await(new TimeoutCancellation(2));
            if ($cancel) {
                $cancelled = true;
                try {
                    $waiting->await(new TimeoutCancellation(2));
                    $this->fail('Expected cancellation while the owner still holds its lock.');
                } catch (\RuntimeException $e) {
                    $this->assertStringContainsString('cancelled', $e->getMessage());
                }
                $this->assertSame("old\n", file_get_contents($path));
            }
            flock($stream, \LOCK_UN);
            if (!$cancel) {
                $this->assertStringContainsString('Applied patch', $waiting->await(new TimeoutCancellation(2)));
            }
            // A cancelled waiter must not retain the inode lock or alter content.
            ($this->editFileTool)(new EditFileArgumentsDTO(path: $path, patch: $cancel ? "@@\n-old\n+new" : "@@\n-new\n+new"));
            $this->assertSame("new\n", file_get_contents($path));
        } finally {
            $cancelled = true;
            flock($stream, \LOCK_UN);
            fclose($stream);
        }
    }

    public function testPatchFailureReleasesInodeLock(): void
    {
        $path = $this->tmpDir.'/failed-patch.txt';
        file_put_contents($path, "same\nsame\n");
        foreach (['not a patch', "@@\n-same\n+new"] as $patch) {
            try {
                ($this->editFileTool)(new EditFileArgumentsDTO(path: $path, patch: $patch));
                $this->fail('Expected malformed or ambiguous patch refusal.');
            } catch (ToolCallException $e) {
                $this->assertStringContainsString('target file is untouched', $e->getMessage());
            }
            $stream = fopen($path, 'r+b');
            $this->assertTrue(flock($stream, \LOCK_EX | \LOCK_NB));
            flock($stream, \LOCK_UN);
            fclose($stream);
        }
        $this->assertSame("same\nsame\n", file_get_contents($path));
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

        ($this->editFileTool)(new EditFileArgumentsDTO(path: $linkPath, patch: $patch));
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

        ($this->editFileTool)(new EditFileArgumentsDTO(path: $pathA, patch: $patch));
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
            ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
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

        $result = ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
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

        $result = ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
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
            ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
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

        ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
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

        ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
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

        ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
        $this->assertSame("alpha\n-1 something\nBETA\n", file_get_contents($targetPath));
    }

    /**
     * Session 37: second plain @@ after body starts a sequential hunk; overlapping context
     * consumed by hunk #1 fails as stale without partial writes.
     */
    public function testOverlappingSecondHunkFailsAtomicallyWithSequentialGuidance(): void
    {
        $targetPath = $this->tmpDir.'/overlap_hunks.txt';
        $original = "alpha\nbeta\ngamma\ndelta\n";
        file_put_contents($targetPath, $original);

        // Hunk #1 would match alpha/beta; hunk #2 reuses beta (already past lineIndex) → stale.
        $patch = <<<'PATCH'
@@
 alpha
-beta
+BETA
 gamma
@@
 beta
-gamma
+GAMMA
PATCH;

        try {
            ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('E_PATCH_STALE', $e->getMessage());
            $this->assertStringContainsString('Hunk #2', $e->getMessage());
            $this->assertStringContainsString('No changes from this attempt were applied', $e->getMessage());
            $hint = $e->hint() ?? '';
            $this->assertStringContainsString('sequential, non-overlapping', $hint);
            $this->assertStringContainsString('combined into one hunk', $hint);
            $this->assertStringContainsString('regenerate the patch', $hint);
        }

        $this->assertSame($original, file_get_contents($targetPath));
    }

    /**
     * Session 37 / Codex alignment: mid-file hunk with *** End of File falls back to a unique
     * forward match when the old block is not at physical EOF.
     */
    public function testMidFileHunkWithEndOfFileMarkerFallsBackToUniqueMatch(): void
    {
        $targetPath = $this->tmpDir.'/mid_eof.txt';
        file_put_contents($targetPath, "keep\nmiddle\ntail\n");

        $patch = <<<'PATCH'
@@
 keep
-middle
+MIDDLE
*** End of File
PATCH;

        $result = ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
        $this->assertStringContainsString('Applied patch', $result);
        $this->assertSame("keep\nMIDDLE\ntail\n", file_get_contents($targetPath));
    }

    /**
     * *** End of File prefers the physical EOF occurrence even when the same old block
     * also appears earlier in the file.
     */
    public function testEndOfFileMarkerPrefersPhysicalEofOverEarlierDuplicate(): void
    {
        $targetPath = $this->tmpDir.'/eof_prefers_end.txt';
        file_put_contents($targetPath, "block\nend\nnoise\nblock\nend\n");

        $patch = <<<'PATCH'
@@
 block
-end
+END
*** End of File
PATCH;

        ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
        $this->assertSame("block\nend\nnoise\nblock\nEND\n", file_get_contents($targetPath));
    }

    /**
     * EOF-marked hunk with no physical-EOF match still rejects ambiguous non-EOF fallbacks.
     */
    public function testEndOfFileFallbackRejectsAmbiguousNonEofMatch(): void
    {
        $targetPath = $this->tmpDir.'/eof_ambiguous.txt';
        $original = "block\nmiddle\nblock\ntail\n";
        file_put_contents($targetPath, $original);

        $patch = <<<'PATCH'
@@
-block
+BLOCK
*** End of File
PATCH;

        try {
            ($this->editFileTool)(new EditFileArgumentsDTO(path: $targetPath, patch: $patch));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('E_PATCH_AMBIGUOUS', $e->getMessage());
            $this->assertStringContainsString('No changes from this attempt were applied', $e->getMessage());
        }

        $this->assertSame($original, file_get_contents($targetPath));
    }
}
