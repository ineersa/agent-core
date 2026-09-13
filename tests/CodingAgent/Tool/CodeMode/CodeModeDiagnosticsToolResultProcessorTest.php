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
        $this->assertSame(['stdout' => 'diag'], $afterCap->details['code_mode_diagnostics'] ?? null);
        $notifications = $afterCap->details['model_notifications'] ?? null;
        $this->assertIsArray($notifications);
        $kinds = array_map(static fn (array $n): string => (string) ($n['kind'] ?? ''), $notifications);
        $this->assertContains('script_diagnostics', $kinds);
        $this->assertContains('output_capped', $kinds);
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
