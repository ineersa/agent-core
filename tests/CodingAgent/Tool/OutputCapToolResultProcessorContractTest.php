<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Domain\Notification\ModelNotificationDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\OutputCapToolResultProcessor;
use PHPUnit\Framework\TestCase;

/**
 * Integration contract proof: a large tool result passing through the generic
 * OutputCapToolResultProcessor produces a ModelNotification, compact ToolResult
 * content, sanitized details without raw full output, and attaches structured
 * model_notifications / output_cap metadata.
 *
 * Test thesis: when output exceeds the cap, the processor must:
 *  - produce a ToolResult with compact content (tool_name completed)
 *  - remove raw_result from details
 *  - attach a model_notifications array with exactly one notification
 *  - the notification has delivery=tool_result_replace and source=output_cap
 *  - output_cap audit metadata is present
 *  - the notification text is the exact model-facing cap notice
 *  - when output fits within the cap, the result passes through unchanged
 */
final class OutputCapToolResultProcessorContractTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/hatfield-opc-contract-'.bin2hex(random_bytes(4));
        @mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = glob($this->tmpDir.'/*') ?: [];
            foreach ($files as $f) {
                @unlink($f);
            }
            @rmdir($this->tmpDir);
        }
    }

    public function testOversizedResultProducesNotificationAndCompactContent(): void
    {
        // Cap set low so our test text triggers capping.
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 50, docCap: 50);
        $outputCap = new OutputCap($cfg);
        $processor = new OutputCapToolResultProcessor($outputCap);

        $rawSentinel = 'RAW_SENTINEL_'.bin2hex(random_bytes(8));
        $largeText = str_repeat('A', 300).$rawSentinel;

        $result = new ToolResult(
            toolCallId: 'call-1',
            toolName: 'read',
            content: [['type' => 'text', 'text' => $largeText]],
            details: [
                'raw_result' => $largeText,
                'mode' => 'parallel',
                'duration_ms' => 42,
                'sources' => [['name' => 'src', 'reference' => '/x', 'content' => 'x']],
            ],
            isError: false,
        );

        $toolCall = new ToolCall(
            toolCallId: 'call-1',
            toolName: 'read',
            arguments: ['path' => './test.txt'],
            orderIndex: 0,
        );

        $processed = $processor->process($result, $toolCall);

        // Compact content: not the raw text.
        $this->assertNotSame($largeText, $processed->content[0]['text']);
        $this->assertStringContainsString('read completed', $processed->content[0]['text']);

        // Raw output must NOT be in details.
        $details = \is_array($processed->details) ? $processed->details : [];
        $this->assertArrayNotHasKey('raw_result', $details,
            'Raw full output must not leak into capped ToolResult details');

        // Safe operational metadata must be preserved.
        $this->assertArrayHasKey('mode', $details);
        $this->assertSame('parallel', $details['mode']);
        $this->assertArrayHasKey('sources', $details);

        // model_notifications must be present with exactly one notification.
        $this->assertArrayHasKey('model_notifications', $details);
        $notifications = $details['model_notifications'];
        $this->assertIsArray($notifications);
        $this->assertCount(1, $notifications);

        $notif = $notifications[0];
        $this->assertIsArray($notif);
        $this->assertSame('output_cap', $notif['source']);
        $this->assertSame('output_capped', $notif['kind']);
        $this->assertSame('warning', $notif['severity']);
        $this->assertSame('tool_result_replace', $notif['delivery']);
        $this->assertStringContainsString('Output capped', $notif['text']);
        $this->assertNotEmpty($notif['id']);
        $this->assertSame('call-1', $notif['tool_call_id'] ?? null);
        $this->assertSame('read', $notif['tool_name'] ?? null);

        // Notification metadata has cap metrics.
        $this->assertArrayHasKey('cap', $notif['metadata']);
        $this->assertArrayHasKey('saved_path', $notif['metadata']);

        // output_cap audit metadata must be present.
        $this->assertArrayHasKey('output_cap', $details);
        $this->assertTrue($details['output_cap']['capped']);
        $this->assertSame(50, $details['output_cap']['cap']);
    }

    public function testResultUnderCapPassesThroughUnchanged(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 5000, docCap: 5000);
        $outputCap = new OutputCap($cfg);
        $processor = new OutputCapToolResultProcessor($outputCap);

        $smallText = 'Small result text';

        $result = new ToolResult(
            toolCallId: 'call-2',
            toolName: 'bash',
            content: [['type' => 'text', 'text' => $smallText]],
            details: ['raw_result' => $smallText],
            isError: false,
        );

        $toolCall = new ToolCall(
            toolCallId: 'call-2',
            toolName: 'bash',
            arguments: [],
            orderIndex: 1,
        );

        $processed = $processor->process($result, $toolCall);

        // Content unchanged.
        $this->assertSame($smallText, $processed->content[0]['text']);

        // Details: raw_result preserved (no cap applied).
        $details = \is_array($processed->details) ? $processed->details : [];
        $this->assertArrayHasKey('raw_result', $details);
        $this->assertSame($smallText, $details['raw_result']);

        // No model_notifications added for non-capped results.
        $this->assertArrayNotHasKey('model_notifications', $details);
        $this->assertArrayNotHasKey('output_cap', $details);
    }

    public function testEmptyContentPassesThrough(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 5000);
        $outputCap = new OutputCap($cfg);
        $processor = new OutputCapToolResultProcessor($outputCap);

        $result = new ToolResult(
            toolCallId: 'call-3',
            toolName: 'read',
            content: [],
            details: ['raw_result' => ''],
            isError: false,
        );

        $toolCall = new ToolCall(
            toolCallId: 'call-3',
            toolName: 'read',
            arguments: [],
            orderIndex: 2,
        );

        $processed = $processor->process($result, $toolCall);

        // Returns unchanged.
        $this->assertSame([], $processed->content);
        $this->assertArrayHasKey('raw_result', \is_array($processed->details) ? $processed->details : []);
    }
}
