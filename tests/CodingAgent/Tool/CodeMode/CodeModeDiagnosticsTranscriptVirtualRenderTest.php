<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\AgentCore\Application\Handler\ToolCallResultFactory;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Notification\ModelNotificationCodec;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Runtime\Projection\SubagentProgressDisplayFormatter;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ModelNotificationProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\ToolProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticsToolResultProcessor;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeExecutionResult;
use Ineersa\CodingAgent\Tool\CodeModeTool;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\OutputCapToolResultProcessor;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult as SymfonyToolResult;
use Symfony\AI\Platform\Result\ToolCall as SymfonyToolCall;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * Virtual product proof: code_mode diagnostics stay inside the ordinary
 * expandable ToolResult card. No standalone script_diagnostics System block.
 *
 * Lower layers cannot prove ChatScreen collapse/expansion without the live
 * widget → ScreenBuffer path used here.
 *
 * @covers \Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticsToolResultProcessor
 */
final class CodeModeDiagnosticsTranscriptVirtualRenderTest extends TestCase
{
    private const string SESSION_ID = 'code-mode-diagnostics-virtual';

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('code-mode-diagnostics-virtual');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    public function testUncappedDiagnosticsCollapseAndExpandInsideToolCard(): void
    {
        $lines = [];
        for ($i = 0; $i < 12; ++$i) {
            $lines[] = \sprintf('diag_line_%02d', $i);
        }
        $stdout = implode("\n", $lines);
        $visible = $this->executeVisibleResult(new CodeModeExecutionResult('ok', [
            'stdout' => $stdout,
        ]), defaultCap: 50_000);

        $this->assertStringContainsString('code_mode diagnostics', $visible);
        $this->assertStringContainsString('diag_line_00', $visible);
        $this->assertStringContainsString('diag_line_11', $visible);

        [$projector, $blocksCollapsed] = $this->projectToolResult(
            toolCallId: 'call-uncapped',
            visibleResult: $visible,
            details: [],
        );

        $this->assertSame([], array_values(array_filter(
            $blocksCollapsed,
            static fn ($block): bool => TranscriptBlockKindEnum::System === $block->kind,
        )));

        $collapsedState = new TranscriptDisplayState(previewableBlocksExpanded: false);
        $harness = new VirtualTuiHarness(
            sessionId: self::SESSION_ID,
            displayConfig: new TranscriptDisplayConfig(toolResultPreviewLines: 3),
            displayState: $collapsedState,
        );
        $harness->screen()->setTranscriptBlocks($blocksCollapsed);
        $harness->screen()->setWorkingVisible(false);

        $collapsed = $harness->plainScreenText();
        $this->assertStringContainsString('code_mode', $collapsed);
        $this->assertStringContainsString('code_mode diagnostics', $collapsed);
        $this->assertStringContainsString('… 13 more lines', $collapsed);
        $this->assertStringNotContainsString('diag_line_00', $collapsed);
        $this->assertStringNotContainsString('diag_line_11', $collapsed);
        $this->assertStringNotContainsString('script_diagnostics', $collapsed);

        $collapsedState->previewableBlocksExpanded = true;
        $harness->screen()->setTranscriptBlocks($projector->blocks());
        $expanded = $harness->plainScreenText();
        $this->assertStringContainsString('diag_line_00', $expanded);
        $this->assertStringContainsString('diag_line_11', $expanded);
        $this->assertStringNotContainsString('script_diagnostics', $expanded);
        $this->assertStringNotContainsString('… 13 more lines', $expanded);
    }

    public function testCappedDiagnosticsShowOnlyCapNoticeWithoutStandaloneDiagnosticsBlock(): void
    {
        $domainResult = $this->executeDomainResult(new CodeModeExecutionResult('ok', [
            'stdout' => str_repeat("diag_line\n", 4_000),
        ]), defaultCap: 50, docCap: 50);

        $this->assertSame(CodeModeTool::NAME.' completed', $domainResult->content[0]['text'] ?? null);
        $details = \is_array($domainResult->details) ? $domainResult->details : [];
        $notifications = ModelNotificationCodec::denormalizeFromDetails(
            AttributeSerializerValidatorTestFactory::denormalizer(),
            $details,
        );
        $kinds = array_map(static fn (object $n): string => $n->kind, $notifications);
        $this->assertNotContains('script_diagnostics', $kinds);
        $this->assertContains('output_capped', $kinds);
        $this->assertCount(1, $notifications);

        [$projector, $blocks] = $this->projectToolResult(
            toolCallId: 'call-capped',
            visibleResult: (string) ($domainResult->content[0]['text'] ?? ''),
            details: $details,
        );

        $systemBlocks = array_values(array_filter(
            $blocks,
            static fn ($block): bool => TranscriptBlockKindEnum::System === $block->kind,
        ));
        $this->assertCount(1, $systemBlocks);
        $this->assertSame('output_capped', $systemBlocks[0]->meta['kind'] ?? null);
        $this->assertStringContainsString('capped', strtolower($systemBlocks[0]->text));
        $this->assertStringNotContainsString('code_mode diagnostics', $systemBlocks[0]->text);

        $harness = new VirtualTuiHarness(
            sessionId: self::SESSION_ID,
            displayConfig: new TranscriptDisplayConfig(toolResultPreviewLines: 3),
            displayState: new TranscriptDisplayState(previewableBlocksExpanded: false),
        );
        $harness->screen()->setTranscriptBlocks($projector->blocks());
        $harness->screen()->setWorkingVisible(false);
        $plain = $harness->plainScreenText();

        $this->assertStringContainsString('code_mode completed', $plain);
        $this->assertStringContainsString('[Output capped:', $plain);
        $this->assertStringNotContainsString('code_mode diagnostics', $plain);
        $this->assertStringNotContainsString('diag_line', $plain);
        $this->assertStringNotContainsString('script_diagnostics', $plain);
    }

    private function executeVisibleResult(CodeModeExecutionResult $raw, int $defaultCap, ?int $docCap = null): string
    {
        $domainResult = $this->executeDomainResult($raw, $defaultCap, $docCap);

        return (string) ($domainResult->content[0]['text'] ?? '');
    }

    private function executeDomainResult(CodeModeExecutionResult $raw, int $defaultCap, ?int $docCap = null): \Ineersa\AgentCore\Domain\Tool\ToolResult
    {
        $toolbox = new class($raw) implements ToolboxInterface {
            public function __construct(private CodeModeExecutionResult $raw)
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

        $capCfg = new OutputCapConfig(
            storageDir: $this->tmpDir,
            defaultCap: $defaultCap,
            docCap: $docCap ?? $defaultCap,
        );
        $executor = new ToolExecutor(
            defaultMode: 'sequential',
            maxParallelism: 1,
            resultStore: new ToolExecutionResultStore(),
            toolbox: $toolbox,
            toolResultProcessors: [
                new CodeModeDiagnosticsToolResultProcessor(),
                new OutputCapToolResultProcessor(
                    new OutputCap($capCfg, new LockFactory(new FlockStore($this->tmpDir)), new NullLogger()),
                    AttributeSerializerValidatorTestFactory::denormalizer(),
                ),
            ],
        );

        return $executor->execute(new ToolCall(
            toolCallId: 'call-virtual',
            toolName: CodeModeTool::NAME,
            arguments: ['script' => 'echo "diag"; return "ok";'],
            orderIndex: 0,
            runId: self::SESSION_ID,
        ));
    }

    /**
     * @param array<string, mixed> $details
     *
     * @return array{0: TranscriptProjector, 1: list<\Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock>}
     */
    private function projectToolResult(string $toolCallId, string $visibleResult, array $details): array
    {
        $serializer = AttributeSerializerValidatorTestFactory::serializer();
        $message = new ExecuteToolCall(
            runId: self::SESSION_ID,
            turnNo: 1,
            stepId: 'step-1',
            attempt: 1,
            idempotencyKey: 'ik-'.$toolCallId,
            toolCallId: $toolCallId,
            toolName: CodeModeTool::NAME,
            args: ['script' => 'return "ok";'],
            orderIndex: 0,
        );
        $domainResult = new \Ineersa\AgentCore\Domain\Tool\ToolResult(
            toolCallId: $toolCallId,
            toolName: CodeModeTool::NAME,
            content: [['type' => 'text', 'text' => $visibleResult]],
            details: $details,
        );
        $envelope = ToolCallResultFactory::fromExecuteToolCallAndToolResult($message, $domainResult);
        $codec = new ToolExecutionEndPayloadCodec($serializer);
        $translator = new RuntimeEventTranslator(new EventDispatcher(), $codec);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ToolProjectionSubscriber(
            new SubagentProgressDisplayFormatter(),
            AttributeSerializerValidatorTestFactory::denormalizer(),
        ));
        $dispatcher->addSubscriber(new ModelNotificationProjectionSubscriber(
            AttributeSerializerValidatorTestFactory::denormalizer(),
        ));
        $projector = new TranscriptProjector($dispatcher, new TranscriptProjectionState());

        $start = new RunEvent(self::SESSION_ID, 1, 1, RunEventTypeEnum::ToolExecutionStart->value, [
            'tool_call_id' => $toolCallId,
            'tool_name' => CodeModeTool::NAME,
            'arguments' => ['script' => 'return "ok";'],
        ]);
        $end = new RunEvent(
            self::SESSION_ID,
            2,
            1,
            RunEventTypeEnum::ToolExecutionEnd->value,
            $codec->toEventPayload($envelope),
        );
        foreach ([$start, $end] as $event) {
            $runtimeEvent = $translator->translate($event);
            $this->assertNotNull($runtimeEvent);
            $projector->accept($runtimeEvent);
        }

        $notifications = ModelNotificationCodec::denormalizeFromDetails(
            AttributeSerializerValidatorTestFactory::denormalizer(),
            $details,
        );
        $seq = 3;
        foreach (ModelNotificationCodec::toEventSpecs($serializer, $notifications) as $notifSpec) {
            $runtimeEvent = $translator->translate(new RunEvent(
                self::SESSION_ID,
                $seq,
                1,
                $notifSpec['type'],
                $notifSpec['payload'],
            ));
            ++$seq;
            $this->assertNotNull($runtimeEvent);
            $projector->accept($runtimeEvent);
        }

        return [$projector, $projector->blocks()];
    }
}
