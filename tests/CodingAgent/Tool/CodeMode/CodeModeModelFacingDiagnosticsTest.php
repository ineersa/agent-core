<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationCodec;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
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
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult as SymfonyToolResult;
use Symfony\AI\Platform\Result\ToolCall as SymfonyToolCall;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Thesis: returning-script stdout/stderr become model-facing tool text through
 * ToolExecutor processors → ToolCallResultFactory → AgentMessageNormalizer.
 * delivery=context alone is insufficient; visible content must carry diagnostics.
 *
 * @covers \Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticsToolResultProcessor
 */
final class CodeModeModelFacingDiagnosticsTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('code-mode-model-facing');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testReturningScriptDiagnosticsAreVisibleInModelToolMessage(): void
    {
        $toolbox = $this->toolboxReturning(new CodeModeExecutionResult('plain', [
            'stdout' => 'OUT',
            'stderr' => 'ERR',
        ]));
        $executor = $this->executor($toolbox, defaultCap: 10_000);
        $toolCall = $this->toolCall('call-visible');

        $domainResult = $executor->execute($toolCall);
        $visible = (string) ($domainResult->content[0]['text'] ?? '');
        $this->assertStringContainsString('plain', $visible);
        $this->assertStringContainsString('code_mode diagnostics', $visible);
        $this->assertStringContainsString("stdout:\nOUT", $visible);
        $this->assertStringContainsString("stderr:\nERR", $visible);

        $envelope = ToolCallResultFactory::fromExecuteToolCallAndToolResult(
            $this->executeMessage('call-visible'),
            $domainResult,
        );
        $serializer = AttributeSerializerValidatorTestFactory::denormalizer();
        $notifications = ModelNotificationCodec::denormalizeFromDetails(
            $serializer,
            $envelope->result['details'] ?? null,
        );
        $message = (new AgentMessageNormalizer())->toolMessage($envelope, $notifications);
        $modelText = (string) ($message->content[0]['text'] ?? '');

        $this->assertStringContainsString('plain', $modelText);
        $this->assertStringContainsString('code_mode diagnostics', $modelText);
        $this->assertStringContainsString("stdout:\nOUT", $modelText);
        $this->assertStringContainsString("stderr:\nERR", $modelText);
        $this->assertNotSame('code_mode completed', $modelText);
    }

    public function testNullReturnWithDiagnosticsRemainsVisible(): void
    {
        $toolbox = $this->toolboxReturning(new CodeModeExecutionResult(null, [
            'stdout' => 'null-out',
        ]));
        $executor = $this->executor($toolbox, defaultCap: 10_000);
        $domainResult = $executor->execute($this->toolCall('call-null'));
        $visible = (string) ($domainResult->content[0]['text'] ?? '');

        $this->assertStringStartsWith("null\n\ncode_mode diagnostics\n", $visible);
        $this->assertStringContainsString("stdout:\nnull-out", $visible);
    }

    public function testOversizedReturnWithDiagnosticsIsStillCapped(): void
    {
        $large = str_repeat('A', 300);
        $toolbox = $this->toolboxReturning(new CodeModeExecutionResult($large, [
            'stdout' => 'diag',
        ]));
        $executor = $this->executor($toolbox, defaultCap: 50);
        $domainResult = $executor->execute($this->toolCall('call-cap'));

        $this->assertSame(CodeModeTool::NAME.' completed', $domainResult->content[0]['text'] ?? null);
        $this->assertArrayNotHasKey('code_mode_diagnostics', \is_array($domainResult->details) ? $domainResult->details : []);

        $envelope = ToolCallResultFactory::fromExecuteToolCallAndToolResult(
            $this->executeMessage('call-cap'),
            $domainResult,
        );
        $serializer = AttributeSerializerValidatorTestFactory::denormalizer();
        $notifications = ModelNotificationCodec::denormalizeFromDetails(
            $serializer,
            $envelope->result['details'] ?? null,
        );
        $message = (new AgentMessageNormalizer())->toolMessage($envelope, $notifications);
        $modelText = (string) ($message->content[0]['text'] ?? '');

        // Cap wins for model-facing text via delivery=tool_result_replace.
        $this->assertNotSame($large, $modelText);
        $this->assertStringContainsString('capped', strtolower($modelText));
    }

    private function executor(ToolboxInterface $toolbox, int $defaultCap): ToolExecutor
    {
        $serializer = new Serializer([new ObjectNormalizer()]);
        $diagnostics = new CodeModeDiagnosticsToolResultProcessor($serializer);
        $capCfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: $defaultCap, docCap: $defaultCap);
        $cap = new OutputCapToolResultProcessor(
            new OutputCap($capCfg, new LockFactory(new FlockStore($this->tmpDir)), new NullLogger()),
            AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        return new ToolExecutor(
            defaultMode: 'sequential',
            maxParallelism: 1,
            resultStore: new ToolExecutionResultStore(),
            toolbox: $toolbox,
            toolResultProcessors: [$diagnostics, $cap],
        );
    }

    private function toolboxReturning(mixed $raw): ToolboxInterface
    {
        return new class($raw) implements ToolboxInterface {
            public function __construct(private mixed $raw)
            {
            }

            public function getTools(): array
            {
                return [];
            }

            public function execute(SymfonyToolCall $toolCall): SymfonyToolResult
            {
                return new SymfonyToolResult($toolCall, $this->raw);
            }
        };
    }

    private function toolCall(string $id): ToolCall
    {
        return new ToolCall(
            toolCallId: $id,
            toolName: CodeModeTool::NAME,
            arguments: ['script' => 'echo "OUT"; fwrite(STDERR, "ERR\\n"); return "plain";'],
            orderIndex: 0,
            runId: 'code-mode-model-facing',
        );
    }

    private function executeMessage(string $toolCallId): ExecuteToolCall
    {
        return new ExecuteToolCall(
            runId: 'code-mode-model-facing',
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'ik-'.$toolCallId,
            toolCallId: $toolCallId,
            toolName: CodeModeTool::NAME,
            args: ['script' => 'return 1;'],
            orderIndex: 0,
            toolIdempotencyKey: null,
            mode: 'sequential',
        );
    }
}
