<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticsToolResultProcessor;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeExecutionResult;
use Ineersa\CodingAgent\Tool\CodeModeTool;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\OutputCapToolResultProcessor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * @covers \Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticsToolResultProcessor
 * @covers \Ineersa\CodingAgent\Tool\CodeMode\CodeModeExecutionResult
 */
final class CodeModeDiagnosticsToolResultProcessorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('code-mode-diagnostics');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testNullReturnBecomesVisibleNullText(): void
    {
        $processor = $this->processor();
        $toolCall = $this->toolCall('call-1', ['script' => 'return null;']);
        $result = new ToolResult(
            toolCallId: 'call-1',
            toolName: CodeModeTool::NAME,
            content: [['type' => 'text', 'text' => '']],
            details: ['raw_result' => null],
        );

        $processed = $processor->process($result, $toolCall);

        $this->assertSame('null', $processed->content[0]['text'] ?? null);
        $this->assertArrayHasKey('raw_result', $processed->details);
        $this->assertNull($processed->details['raw_result']);
    }

    public function testBooleanReturnsBecomeVisibleTrueFalseWithoutDiagnostics(): void
    {
        $processor = $this->processor();

        $trueResult = new ToolResult(
            toolCallId: 'call-true',
            toolName: CodeModeTool::NAME,
            content: [['type' => 'text', 'text' => '1']],
            details: ['raw_result' => true],
        );
        $falseResult = new ToolResult(
            toolCallId: 'call-false',
            toolName: CodeModeTool::NAME,
            content: [['type' => 'text', 'text' => '']],
            details: ['raw_result' => false],
        );

        $this->assertSame('true', $processor->process($trueResult, $this->toolCall('call-true', ['script' => 'return true;']))->content[0]['text'] ?? null);
        $this->assertSame('false', $processor->process($falseResult, $this->toolCall('call-false', ['script' => 'return false;']))->content[0]['text'] ?? null);
    }

    public function testBooleanReturnsRemainVisibleWithDiagnostics(): void
    {
        $processor = $this->processor();
        $toolCall = $this->toolCall('call-bool-diag', ['script' => 'echo "out"; return false;']);
        $result = new ToolResult(
            toolCallId: 'call-bool-diag',
            toolName: CodeModeTool::NAME,
            content: [['type' => 'text', 'text' => '']],
            details: [
                'raw_result' => new CodeModeExecutionResult(false, [
                    'stdout' => 'out',
                ]),
            ],
        );

        $processed = $processor->process($result, $toolCall);
        $visible = (string) ($processed->content[0]['text'] ?? '');

        $this->assertStringStartsWith("false\n\ncode_mode diagnostics\n", $visible);
        $this->assertStringContainsString("stdout:\nout", $visible);
        $this->assertFalse($processed->details['raw_result'] ?? null);
    }

    public function testErrorResultWithoutRawResultIsLeftUntouched(): void
    {
        $processor = $this->processor();
        $toolCall = $this->toolCall('call-err', ['script' => 'throw new RuntimeException("boom");']);
        $result = new ToolResult(
            toolCallId: 'call-err',
            toolName: CodeModeTool::NAME,
            content: [['type' => 'text', 'text' => 'boom']],
            details: ['denied' => false],
            isError: true,
        );

        $processed = $processor->process($result, $toolCall);

        $this->assertSame($result, $processed);
        $this->assertSame('boom', $processed->content[0]['text'] ?? null);
        $this->assertArrayNotHasKey('raw_result', \is_array($processed->details) ? $processed->details : []);
    }

    public function testMissingRawResultIsLeftUntouched(): void
    {
        $processor = $this->processor();
        $toolCall = $this->toolCall('call-missing', ['script' => 'return 1;']);
        $result = new ToolResult(
            toolCallId: 'call-missing',
            toolName: CodeModeTool::NAME,
            content: [['type' => 'text', 'text' => 'code_mode completed']],
            details: ['mode' => 'sequential'],
        );

        $processed = $processor->process($result, $toolCall);

        $this->assertSame($result, $processed);
        $this->assertSame('code_mode completed', $processed->content[0]['text'] ?? null);
    }

    public function testDiagnosticsAreAttachedAsModelNotificationWithoutReplacingReturn(): void
    {
        $processor = $this->processor();
        $toolCall = $this->toolCall('call-2', ['script' => 'echo "out"; return 9;']);
        $result = new ToolResult(
            toolCallId: 'call-2',
            toolName: CodeModeTool::NAME,
            content: [['type' => 'text', 'text' => '{}']],
            details: [
                'raw_result' => new CodeModeExecutionResult(9, [
                    'stdout' => 'out',
                    'stderr' => 'warn',
                ]),
            ],
        );

        $processed = $processor->process($result, $toolCall);

        $visible = (string) ($processed->content[0]['text'] ?? '');
        $this->assertStringStartsWith("9\n\ncode_mode diagnostics\n", $visible);
        $this->assertStringContainsString("stdout:\nout", $visible);
        $this->assertStringContainsString("stderr:\nwarn", $visible);
        $this->assertSame(9, $processed->details['raw_result'] ?? null);
        $this->assertSame(['stdout' => 'out', 'stderr' => 'warn'], $processed->details['code_mode_diagnostics'] ?? null);
        $notifications = $processed->details['model_notifications'] ?? null;
        $this->assertIsArray($notifications);
        $this->assertCount(1, $notifications);
        $this->assertSame('code_mode', $notifications[0]['source'] ?? null);
        $this->assertSame('script_diagnostics', $notifications[0]['kind'] ?? null);
        $this->assertSame('context', $notifications[0]['delivery'] ?? null);
        $this->assertStringContainsString('stdout:', (string) ($notifications[0]['text'] ?? ''));
        $this->assertStringContainsString('stderr:', (string) ($notifications[0]['text'] ?? ''));
    }

    public function testDiagnosticsThenOutputCapPreservesCapAndKeepsNotifications(): void
    {
        $diagnostics = $this->processor();
        $capCfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 50, docCap: 50);
        $capProcessor = new OutputCapToolResultProcessor(
            new OutputCap($capCfg, new LockFactory(new FlockStore($this->tmpDir)), new NullLogger()),
            AttributeSerializerValidatorTestFactory::denormalizer(),
        );
        $toolCall = $this->toolCall('call-cap', ['script' => 'echo "diag"; return "'.str_repeat('A', 300).'";']);
        $result = new ToolResult(
            toolCallId: 'call-cap',
            toolName: CodeModeTool::NAME,
            content: [['type' => 'text', 'text' => '{}']],
            details: [
                'raw_result' => new CodeModeExecutionResult(str_repeat('A', 300), [
                    'stdout' => 'diag',
                ]),
            ],
        );

        $afterDiagnostics = $diagnostics->process($result, $toolCall);
        $afterCap = $capProcessor->process($afterDiagnostics, $toolCall);

        $this->assertSame(CodeModeTool::NAME.' completed', $afterCap->content[0]['text'] ?? null);
        $this->assertArrayNotHasKey('raw_result', \is_array($afterCap->details) ? $afterCap->details : []);
        $this->assertArrayNotHasKey('code_mode_diagnostics', \is_array($afterCap->details) ? $afterCap->details : []);
        $notifications = $afterCap->details['model_notifications'] ?? null;
        $this->assertIsArray($notifications);
        $kinds = array_map(static fn (array $n): string => (string) ($n['kind'] ?? ''), $notifications);
        $this->assertContains('script_diagnostics', $kinds);
        $this->assertContains('output_capped', $kinds);
    }

    public function testErrorPathLargeDiagnosticsUseDocumentCap(): void
    {
        // Early-exit failures already embed prepared stdout/stderr in content.
        // The diagnostics processor leaves isError results alone; OutputCap owns size.
        $capCfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 20000, docCap: 50000);
        $capProcessor = new OutputCapToolResultProcessor(
            new OutputCap($capCfg, new LockFactory(new FlockStore($this->tmpDir)), new NullLogger()),
            AttributeSerializerValidatorTestFactory::denormalizer(),
        );
        $message = "Code-mode PHP subprocess exited without returning a value (exit code 0).\nstdout:\n".str_repeat('E', 60_000);
        $toolCall = $this->toolCall('call-err-cap', ['script' => 'exit(0);']);
        $result = new ToolResult(
            toolCallId: 'call-err-cap',
            toolName: CodeModeTool::NAME,
            content: [['type' => 'text', 'text' => $message]],
            details: ['denied' => false],
            isError: true,
        );

        $afterDiagnostics = $this->processor()->process($result, $toolCall);
        $this->assertSame($result, $afterDiagnostics);

        $afterCap = $capProcessor->process($afterDiagnostics, $toolCall);
        $this->assertSame(CodeModeTool::NAME.' failed', $afterCap->content[0]['text'] ?? null);
        $details = \is_array($afterCap->details) ? $afterCap->details : [];
        $this->assertSame(50000, $details['output_cap']['cap'] ?? null);
        $savedPath = (string) ($details['output_cap']['saved_path'] ?? '');
        $this->assertFileExists($savedPath);
        $this->assertStringContainsString(str_repeat('E', 60_000), (string) file_get_contents($savedPath));
        $this->assertStringNotContainsString('diagnostics truncated', (string) file_get_contents($savedPath));
    }

    public function testIgnoresOtherTools(): void
    {
        $processor = $this->processor();
        $toolCall = new ToolCall(
            toolCallId: 'call-3',
            toolName: 'bash',
            arguments: ['command' => 'echo hi'],
            orderIndex: 0,
        );
        $result = new ToolResult(
            toolCallId: 'call-3',
            toolName: 'bash',
            content: [['type' => 'text', 'text' => 'hi']],
            details: ['raw_result' => null],
        );

        $processed = $processor->process($result, $toolCall);

        $this->assertSame($result, $processed);
    }

    private function processor(): CodeModeDiagnosticsToolResultProcessor
    {
        return new CodeModeDiagnosticsToolResultProcessor(new Serializer([new ObjectNormalizer()]));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function toolCall(string $id, array $arguments): ToolCall
    {
        return new ToolCall(
            toolCallId: $id,
            toolName: CodeModeTool::NAME,
            arguments: $arguments,
            orderIndex: 0,
            runId: 'code-mode-diagnostics-test',
        );
    }
}
