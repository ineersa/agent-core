<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\OutputCapLlmTransformHook;
use Ineersa\CodingAgent\Tool\OutputCapPathResolver;
use Ineersa\CodingAgent\Tool\OutputCapToolResultProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Symfony\Component\String\u;

/**
 * Shared OutputCap path/category resolution for primary processor + late hook.
 *
 * Thesis: both stages must classify the same tool/result as document (50k) or
 * default (20k). Session 37 incorrectly capped hatfield_docs reads and
 * recapped a subagent handoff that the primary processor already allowed.
 */
final class OutputCapPathResolverTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield-output-cap-path');
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpDir) && is_dir($this->tmpDir)) {
            TestDirectoryIsolation::removeDirectory($this->tmpDir);
        }
    }

    #[DataProvider('documentClassificationProvider')]
    public function testResolverClassifiesDocumentCases(
        ?string $toolName,
        array $arguments,
        bool $isError,
        ?string $expectedPath,
    ): void {
        $this->assertSame(
            $expectedPath,
            OutputCapPathResolver::resolveCapPath($toolName, $arguments, $isError),
        );
    }

    /**
     * @return iterable<string, array{0: ?string, 1: array<string, mixed>, 2: bool, 3: ?string}>
     */
    public static function documentClassificationProvider(): iterable
    {
        // Keep distinct matrix rows only. Successful hatfield_docs read, settings
        // dotted-key, and successful subagent/fork handoffs are covered by the
        // end-to-end primary+late proofs below (not re-listed here).
        yield 'markdown read path' => ['read', ['path' => 'docs/settings.md'], false, 'docs/settings.md'];
        yield 'hatfield_docs list is default' => [
            'hatfield_docs',
            ['operation' => 'list'],
            false,
            null,
        ];
        yield 'hatfield_docs read error is default' => [
            'hatfield_docs',
            ['operation' => 'read', 'id' => 'settings'],
            true,
            null,
        ];
        yield 'agent_retrieve handoff is document' => [
            'agent_retrieve',
            ['artifact_id' => 'agent_abc'],
            false,
            'handoff-report.md',
        ];
        yield 'failed fork is default' => ['fork', ['task' => 'x'], true, null];
        yield 'bash remains default' => ['bash', ['command' => 'ls'], false, null];
        yield 'task_list remains default' => ['task_list', [], false, null];
    }

    public function testPrimaryAndLateHookAgreeOnHatfieldDocsReadBetweenCaps(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 20000, docCap: 50000);
        $outputCap = new OutputCap($cfg);
        $processor = new OutputCapToolResultProcessor($outputCap);
        $hook = new OutputCapLlmTransformHook($outputCap);

        $body = str_repeat('D', 25000);
        $this->assertGreaterThan(20000, u($body)->length());
        $this->assertLessThan(50000, u($body)->length());

        $toolCall = new ToolCall(
            toolCallId: 'call-docs-1',
            toolName: 'hatfield_docs',
            arguments: ['operation' => 'read', 'id' => 'settings'],
            orderIndex: 0,
        );
        $result = new ToolResult(
            toolCallId: 'call-docs-1',
            toolName: 'hatfield_docs',
            content: [['type' => 'text', 'text' => $body]],
            details: ['raw_result' => $body],
            isError: false,
        );

        $processed = $processor->process($result, $toolCall);
        $this->assertSame($body, $processed->content[0]['text'] ?? null);
        $this->assertArrayNotHasKey('output_cap', \is_array($processed->details) ? $processed->details : []);

        // Late hook without primary skip metadata must still use docCap.
        $message = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => $body]],
            toolCallId: 'call-docs-1',
            toolName: 'hatfield_docs',
            details: [
                'arguments' => ['operation' => 'read', 'id' => 'settings'],
            ],
        );
        $transformed = $hook->transformContext([$message]);
        $this->assertSame($body, $transformed[0]->content[0]['text'] ?? null);
    }

    public function testPrimaryAndLateHookAgreeOnSubagentHandoffBetweenCaps(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 20000, docCap: 50000);
        $outputCap = new OutputCap($cfg);
        $processor = new OutputCapToolResultProcessor($outputCap);
        $hook = new OutputCapLlmTransformHook($outputCap);

        $handoff = str_repeat('S', 20478);
        $toolCall = new ToolCall(
            toolCallId: 'call-sub-1',
            toolName: 'subagent',
            arguments: ['task' => 'scout'],
            orderIndex: 0,
        );
        $result = new ToolResult(
            toolCallId: 'call-sub-1',
            toolName: 'subagent',
            content: [['type' => 'text', 'text' => $handoff]],
            details: ['raw_result' => $handoff],
            isError: false,
        );

        $processed = $processor->process($result, $toolCall);
        $this->assertSame($handoff, $processed->content[0]['text'] ?? null);

        $message = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => $handoff]],
            toolCallId: 'call-sub-1',
            toolName: 'subagent',
            details: ['arguments' => ['task' => 'scout']],
        );
        $transformed = $hook->transformContext([$message]);
        $this->assertSame($handoff, $transformed[0]->content[0]['text'] ?? null);
    }

    public function testSettingsDottedKeyEndingMdUsesDefaultCapInBothStages(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 20000, docCap: 50000);
        $outputCap = new OutputCap($cfg);
        $processor = new OutputCapToolResultProcessor($outputCap);
        $hook = new OutputCapLlmTransformHook($outputCap);

        $large = str_repeat('K', 25000);
        $toolCall = new ToolCall(
            toolCallId: 'call-settings-1',
            toolName: 'settings',
            arguments: ['operation' => 'read', 'path' => 'docs.example.md'],
            orderIndex: 0,
        );
        $result = new ToolResult(
            toolCallId: 'call-settings-1',
            toolName: 'settings',
            content: [['type' => 'text', 'text' => $large]],
            details: ['raw_result' => $large],
            isError: false,
        );

        $processed = $processor->process($result, $toolCall);
        $details = \is_array($processed->details) ? $processed->details : [];
        $this->assertArrayHasKey('output_cap', $details);
        $this->assertSame(20000, $details['output_cap']['cap']);

        $message = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => $large]],
            toolCallId: 'call-settings-1',
            toolName: 'settings',
            details: [
                'arguments' => ['operation' => 'read', 'path' => 'docs.example.md'],
            ],
        );
        $transformed = $hook->transformContext([$message]);
        $this->assertStringContainsString('Output capped', (string) ($transformed[0]->content[0]['text'] ?? ''));
        $this->assertStringContainsString('20000-char cap', (string) ($transformed[0]->content[0]['text'] ?? ''));
    }

    public function testHatfieldDocsReadOverDocCapIsCappedAtFiftyK(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 20000, docCap: 50000);
        $outputCap = new OutputCap($cfg);
        $processor = new OutputCapToolResultProcessor($outputCap);

        $body = str_repeat('Z', 50001);
        $toolCall = new ToolCall(
            toolCallId: 'call-docs-over',
            toolName: 'hatfield_docs',
            arguments: ['operation' => 'read', 'id' => 'agents'],
            orderIndex: 0,
        );
        $result = new ToolResult(
            toolCallId: 'call-docs-over',
            toolName: 'hatfield_docs',
            content: [['type' => 'text', 'text' => $body]],
            details: ['raw_result' => $body],
            isError: false,
        );

        $processed = $processor->process($result, $toolCall);
        $details = \is_array($processed->details) ? $processed->details : [];
        $this->assertArrayHasKey('output_cap', $details);
        $this->assertSame(50000, $details['output_cap']['cap']);
        $this->assertStringContainsString('hatfield_docs completed', (string) ($processed->content[0]['text'] ?? ''));
    }
}
