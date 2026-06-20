<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
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
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('hatfield-opc-contract');
    }

    protected function tearDown(): void
    {
        if (isset($this->tmpDir) && is_dir($this->tmpDir)) {
            TestDirectoryIsolation::removeDirectory($this->tmpDir);
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
        self::assertNotSame($largeText, $processed->content[0]['text']);
        self::assertStringContainsString('read completed', $processed->content[0]['text']);

        // Raw output must NOT be in details.
        $details = \is_array($processed->details) ? $processed->details : [];
        self::assertArrayNotHasKey('raw_result', $details,
            'Raw full output must not leak into capped ToolResult details');

        // Safe operational metadata must be preserved.
        self::assertArrayHasKey('mode', $details);
        self::assertSame('parallel', $details['mode']);
        self::assertArrayHasKey('sources', $details);

        // model_notifications must be present with exactly one notification.
        self::assertArrayHasKey('model_notifications', $details);
        $notifications = $details['model_notifications'];
        self::assertIsArray($notifications);
        self::assertCount(1, $notifications);

        $notif = $notifications[0];
        self::assertIsArray($notif);
        self::assertSame('output_cap', $notif['source']);
        self::assertSame('output_capped', $notif['kind']);
        self::assertSame('warning', $notif['severity']);
        self::assertSame('tool_result_replace', $notif['delivery']);
        self::assertStringContainsString('Output capped', $notif['text']);
        self::assertStringContainsString('./test.txt', $notif['text'],
            'Read-capped notice must reference the original file path, not the saved artifact');
        self::assertStringContainsString('read(path:', $notif['text']);
        self::assertStringContainsString('limit: 200', $notif['text']);
        self::assertStringContainsString('Do not repeat the original full read or read the saved output with read', $notif['text']);
        self::assertStringNotContainsString('head -200', $notif['text'],
            'Read-capped notice must NOT suggest shell head on saved artifact');
        self::assertNotEmpty($notif['id']);
        self::assertSame('call-1', $notif['tool_call_id'] ?? null);
        self::assertSame('read', $notif['tool_name'] ?? null);

        // Notification metadata has cap metrics.
        self::assertArrayHasKey('cap', $notif['metadata']);
        self::assertArrayHasKey('saved_path', $notif['metadata']);

        // output_cap audit metadata must be present.
        self::assertArrayHasKey('output_cap', $details);
        self::assertTrue($details['output_cap']['capped']);
        self::assertSame(50, $details['output_cap']['cap']);
    }

    public function testGenericCappedResultSuggestsReadOnSavedArtifactWithOffsetLimit(): void
    {
        // Test thesis: generic (non-read) tool output caps use the generic
        // notice from OutputCap::buildCappedNotice(), which now suggests
        // read(savedPath, offset: 1, limit: 200) for chunked inspection
        // and grep for targeted search.  This is safe because the saved
        // cap artefact is rendered tool output text (NOT a read/cat -n
        // artefact that would add double line numbers).
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 50, docCap: 50);
        $outputCap = new OutputCap($cfg);
        $processor = new OutputCapToolResultProcessor($outputCap);

        $largeText = str_repeat('B', 300).'GENERIC_SENTINEL_'.bin2hex(random_bytes(8));

        $result = new ToolResult(
            toolCallId: 'call-g1',
            toolName: 'bash',
            content: [['type' => 'text', 'text' => $largeText]],
            details: ['raw_result' => $largeText],
            isError: false,
        );

        $toolCall = new ToolCall(
            toolCallId: 'call-g1',
            toolName: 'bash',
            arguments: ['command' => 'cat large.log'],
            orderIndex: 3,
        );

        $processed = $processor->process($result, $toolCall);

        $details = \is_array($processed->details) ? $processed->details : [];
        self::assertArrayHasKey('model_notifications', $details);
        $notifications = $details['model_notifications'];
        self::assertCount(1, $notifications);

        $notif = $notifications[0];
        $noticeText = $notif['text'];

        // Generic notice uses read + grep on saved artefact, not shell head.
        self::assertStringContainsString('read(path:', $noticeText,
            'Generic cap notice must suggest read on saved artefact with offset+limit');
        self::assertStringContainsString('limit: 200', $noticeText);
        self::assertStringContainsString('without offset+limit', $noticeText);
        self::assertStringContainsString('Do not rerun the original command', $noticeText);
        self::assertStringNotContainsString('head -200', $noticeText,
            'Generic cap notice must NOT suggest shell head (use read instead)');
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
        self::assertSame($smallText, $processed->content[0]['text']);

        // Details: raw_result preserved (no cap applied).
        $details = \is_array($processed->details) ? $processed->details : [];
        self::assertArrayHasKey('raw_result', $details);
        self::assertSame($smallText, $details['raw_result']);

        // No model_notifications added for non-capped results.
        self::assertArrayNotHasKey('model_notifications', $details);
        self::assertArrayNotHasKey('output_cap', $details);
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
        self::assertSame([], $processed->content);
        self::assertArrayHasKey('raw_result', \is_array($processed->details) ? $processed->details : []);
    }
}
