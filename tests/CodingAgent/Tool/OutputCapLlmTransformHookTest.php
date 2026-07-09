<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\CodingAgent\Config\OutputCapConfig;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\OutputCapLlmTransformHook;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\ToolCallMessage;

/**
 * @covers \Ineersa\CodingAgent\Tool\OutputCapLlmTransformHook
 */
final class OutputCapLlmTransformHookTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir().'/hatfield-output-cap-hook-test-'.bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $this->removeDirectory($this->tmpDir);
        }
    }

    /* ── Core capping: over-cap tool message ── */

    public function testOversizedToolMessageIsCapped(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 500);
        $cap = new OutputCap($cfg);
        $hook = new OutputCapLlmTransformHook($cap);
        $converter = new AgentMessageConverter();

        $prefix = str_repeat('A', 250);
        $suffix = str_repeat('B', 250);
        $sentinel = 'SENTINEL_SHOULD_BE_HIDDEN_'.bin2hex(random_bytes(8));
        $largeText = $prefix."\n".$sentinel."\n".$suffix;

        $message = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => $largeText]],
            toolCallId: 'call-1',
            toolName: 'some_tool',
        );

        $transformed = $hook->transformContext([$message]);
        $messageBag = $converter->toMessageBag($transformed);

        $toolMessages = array_filter(
            $messageBag->getMessages(),
            static fn (object $m): bool => $m instanceof ToolCallMessage,
        );

        $this->assertCount(1, $toolMessages);

        $toolMsg = reset($toolMessages);
        $this->assertInstanceOf(ToolCallMessage::class, $toolMsg);

        $providerContent = $toolMsg->getContent();
        $this->assertIsString($providerContent);

        $this->assertStringContainsString('Output capped', $providerContent);
        $this->assertStringNotContainsString($sentinel, $providerContent);

        // The persisted file must contain the full sentinel.
        $this->assertDirectoryExists($cfg->storageDir);
        $files = glob($cfg->storageDir.'/*.txt') ?: [];
        $this->assertNotEmpty($files, 'Expected at least one persisted file');

        $foundSentinel = false;
        foreach ($files as $file) {
            if (str_contains((string) file_get_contents($file), $sentinel)) {
                $foundSentinel = true;
                break;
            }
        }
        $this->assertTrue($foundSentinel, 'Persisted file must contain the full sentinel');
    }

    /* ── Details fallback capping ── */

    public function testToolMessageWithEmptyTextButLargeDetailsIsCapped(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 500);
        $cap = new OutputCap($cfg);
        $hook = new OutputCapLlmTransformHook($cap);
        $converter = new AgentMessageConverter();

        $sentinel = 'DETAILS_SENTINEL_'.bin2hex(random_bytes(8));
        $largeResult = str_repeat('X', 600).$sentinel;

        // Simulate a tool result where the text content is empty but
        // `details.raw_result` contains a huge string. This pattern
        // occurs when tools emit only structured details, leaving
        // AgentMessageNormalizer to embed raw_result in the JSON
        // text part, or when the text part is somehow empty and
        // AgentMessageConverter falls back to stringifying details.
        $message = new AgentMessage(
            role: 'tool',
            content: [],
            toolCallId: 'call-2',
            toolName: 'extension_tool',
            details: ['raw_result' => $largeResult],
        );

        $transformed = $hook->transformContext([$message]);
        $messageBag = $converter->toMessageBag($transformed);

        $toolMessages = array_filter(
            $messageBag->getMessages(),
            static fn (object $m): bool => $m instanceof ToolCallMessage,
        );

        $this->assertCount(1, $toolMessages);

        $toolMsg = reset($toolMessages);
        $providerContent = $toolMsg->getContent();
        $this->assertIsString($providerContent);

        $this->assertStringContainsString('Output capped', $providerContent);
        $this->assertStringNotContainsString($sentinel, $providerContent);
    }

    /* ── Small message passes through ── */

    public function testSmallToolMessageIsUnchanged(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 500);
        $cap = new OutputCap($cfg);
        $hook = new OutputCapLlmTransformHook($cap);

        $smallText = 'Hello, tool!';

        $message = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => $smallText]],
            toolCallId: 'call-3',
            toolName: 'safe_tool',
        );

        $transformed = $hook->transformContext([$message]);

        $this->assertCount(1, $transformed);
        $this->assertSame('tool', $transformed[0]->role);
        $this->assertSame($smallText, $transformed[0]->content[0]['text'] ?? '');
    }

    /* ── Non-tool messages are untouched ── */

    public function testNonToolMessagesAreUnchanged(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 10);
        $cap = new OutputCap($cfg);
        $hook = new OutputCapLlmTransformHook($cap);

        $userMsg = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => str_repeat('U', 500)]],
        );

        $assistantMsg = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => str_repeat('A', 500)]],
        );

        $systemMsg = new AgentMessage(
            role: 'system',
            content: [['type' => 'text', 'text' => str_repeat('S', 500)]],
        );

        $transformed = $hook->transformContext([$userMsg, $assistantMsg, $systemMsg]);

        $this->assertCount(3, $transformed);
        $this->assertSame('user', $transformed[0]->role);
        $this->assertSame('assistant', $transformed[1]->role);
        $this->assertSame('system', $transformed[2]->role);

        // Content is unchanged — even though it's way over the 10-char cap
        $this->assertSame(str_repeat('U', 500), $transformed[0]->content[0]['text'] ?? '');
    }

    /* ── Preserves metadata fields ── */

    public function testMetadataFieldsArePreserved(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 500);
        $cap = new OutputCap($cfg);
        $hook = new OutputCapLlmTransformHook($cap);

        $timestamp = new \DateTimeImmutable('2025-01-01 12:00:00');

        $message = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => 'small']],
            timestamp: $timestamp,
            name: 'tool-executor',
            toolCallId: 'call-meta',
            toolName: 'meta_tool',
            details: ['raw_result' => 'meta content'],
            isError: true,
            metadata: ['order_index' => 7],
        );

        $transformed = $hook->transformContext([$message]);

        $this->assertCount(1, $transformed);
        $t = $transformed[0];

        $this->assertSame('tool', $t->role);
        $this->assertNotNull($t->timestamp);
        $this->assertSame($timestamp->getTimestamp(), $t->timestamp->getTimestamp());
        $this->assertSame('tool-executor', $t->name);
        $this->assertSame('call-meta', $t->toolCallId);
        $this->assertSame('meta_tool', $t->toolName);
        $this->assertSame(['raw_result' => 'meta content'], $t->details);
        $this->assertTrue($t->isError);
        $this->assertSame(['order_index' => 7], $t->metadata);
    }

    /* ── Image ref parts are preserved ── */

    public function testImageRefContentPartsArePreserved(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 500);
        $cap = new OutputCap($cfg);
        $hook = new OutputCapLlmTransformHook($cap);

        $message = new AgentMessage(
            role: 'tool',
            content: [
                ['type' => 'text', 'text' => 'tool result text'],
                [
                    'type' => 'image_ref',
                    'path' => '/tmp/test-image.jpg',
                    'media_type' => 'image/jpeg',
                    'width' => 800,
                    'height' => 600,
                    'bytes' => 12345,
                ],
            ],
            toolCallId: 'call-img',
            toolName: 'view_image',
        );

        $transformed = $hook->transformContext([$message]);

        $this->assertCount(1, $transformed);
        $content = $transformed[0]->content;
        $this->assertCount(2, $content, 'Expected text + image_ref content parts');

        $types = array_column($content, 'type');
        $this->assertContains('text', $types);
        $this->assertContains('image_ref', $types);

        // Image ref data should be preserved
        foreach ($content as $part) {
            if (($part['type'] ?? '') === 'image_ref') {
                $this->assertSame('/tmp/test-image.jpg', $part['path']);
                $this->assertSame(800, $part['width']);
                $this->assertSame(600, $part['height']);
            }
        }
    }

    /* ── Multi-text parts combined ── */

    public function testMultipleTextPartsAreCombinedForCapping(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 500);
        $cap = new OutputCap($cfg);
        $hook = new OutputCapLlmTransformHook($cap);

        // Each part individually small, but combined exceeds cap
        $sentinel = 'MULTI_PART_SENTINEL_'.bin2hex(random_bytes(8));

        $message = new AgentMessage(
            role: 'tool',
            content: [
                ['type' => 'text', 'text' => str_repeat('P', 200)],
                ['type' => 'text', 'text' => str_repeat('Q', 200)],
                ['type' => 'text', 'text' => $sentinel],
                ['type' => 'text', 'text' => str_repeat('R', 150)],
            ],
            toolCallId: 'call-multi',
            toolName: 'multi_part_tool',
        );

        $transformed = $hook->transformContext([$message]);

        $this->assertCount(1, $transformed);
        // Content should be a single text part (combined and capped)
        $content = $transformed[0]->content;
        $this->assertCount(1, $content);
        $this->assertStringContainsString('Output capped', $content[0]['text'] ?? '');
        $this->assertStringNotContainsString($sentinel, $content[0]['text'] ?? '');
    }

    /* ── Empty tool message passes through ── */

    public function testEmptyToolMessagePassesThrough(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 10);
        $cap = new OutputCap($cfg);
        $hook = new OutputCapLlmTransformHook($cap);

        $message = new AgentMessage(
            role: 'tool',
            content: [],
            toolCallId: 'call-empty',
            toolName: 'empty_tool',
        );

        $transformed = $hook->transformContext([$message]);

        $this->assertCount(1, $transformed);
        $this->assertSame([], $transformed[0]->content);
    }

    /* ── Stable late-hook notification ID ── */

    /**
     * Test thesis: repeated late-hook transforms of the same oversized
     * AgentMessage must produce the same model_notification ID.  The previous
     * code hashed noticeText (which includes a random saved path), causing
     * every transform to create a new ID and duplicate TUI notification
     * blocks / events.  The fix uses original content hash instead.
     */
    public function testLateHookProducesStableNotificationIdOnRepeatTransform(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 100);
        $cap = new OutputCap($cfg);
        $hook = new OutputCapLlmTransformHook($cap);

        $largeText = str_repeat('X', 500);

        $message = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => $largeText]],
            toolCallId: 'call-stable',
            toolName: 'some_tool',
        );

        $first = $hook->transformContext([$message]);
        $second = $hook->transformContext([$message]);

        $this->assertCount(1, $first);
        $this->assertCount(1, $second);

        $notifsA = \is_array($first[0]->metadata['model_notifications'] ?? null)
            ? $first[0]->metadata['model_notifications']
            : [];
        $notifsB = \is_array($second[0]->metadata['model_notifications'] ?? null)
            ? $second[0]->metadata['model_notifications']
            : [];

        $this->assertCount(1, $notifsA);
        $this->assertCount(1, $notifsB);

        $this->assertSame($notifsA[0]['id'], $notifsB[0]['id'],
            'Repeated late-hook transforms must produce the same notification ID');

        // Saved paths differ (each invocation persists to a new random file)
        // but the notification identity is stable.
        $this->assertNotSame(
            $notifsA[0]['metadata']['saved_path'] ?? null,
            $notifsB[0]['metadata']['saved_path'] ?? null,
            'Sanity: saved paths should differ across invocations',
        );
    }

    /* ── Read-tool late hook produces original-path guidance ── */

    /**
     * Test thesis: when the late defense-in-depth hook caps a read-tool
     * AgentMessage, the notice must guide follow-up reads to the ORIGINAL
     * file path (not the saved output-cap artifact).  Reading the saved
     * artifact with the read tool adds presentation noise; guide follow-up reads to the original path.
     */
    public function testLateHookReadNoticeUsesOriginalPathNotSavedArtifact(): void
    {
        $cfg = new OutputCapConfig(storageDir: $this->tmpDir, defaultCap: 50);
        $outputCap = new OutputCap($cfg);
        $hook = new OutputCapLlmTransformHook($outputCap);
        $converter = new AgentMessageConverter();

        $sentinel = 'READ_SENTINEL_'.bin2hex(random_bytes(8));
        $largeText = str_repeat('R', 200)."\n".$sentinel;

        $message = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => $largeText]],
            toolCallId: 'call-read-late',
            toolName: 'read',
            details: [
                'arguments' => ['path' => './src/file.php', 'offset' => 42],
                'raw_result' => $largeText,
            ],
        );

        $transformed = $hook->transformContext([$message]);
        $messageBag = $converter->toMessageBag($transformed);

        $toolMessages = array_filter(
            $messageBag->getMessages(),
            static fn (object $m): bool => $m instanceof ToolCallMessage,
        );
        $this->assertCount(1, $toolMessages);

        $toolMsg = reset($toolMessages);
        $providerContent = $toolMsg->getContent();
        $this->assertIsString($providerContent);

        // Must cap.
        $this->assertStringContainsString('Output capped', $providerContent);
        $this->assertStringNotContainsString($sentinel, $providerContent);

        // Must reference original file, NOT saved artifact via read.
        $this->assertStringContainsString('./src/file.php', $providerContent,
            'Late-hook read notice must reference original file path');
        $this->assertStringContainsString('read(path:', $providerContent);
        $this->assertStringContainsString('offset: 42', $providerContent,
            'Late-hook read notice must use original offset');
        $this->assertStringContainsString('limit: 200', $providerContent);
        $this->assertStringNotContainsString('head -200', $providerContent,
            'Late-hook read notice must NOT suggest shell head (generic path)');

        // The notification text in metadata should match what was sent to provider.
        $notifications = \is_array($transformed[0]->metadata['model_notifications'] ?? null)
            ? $transformed[0]->metadata['model_notifications']
            : [];
        $this->assertCount(1, $notifications);
        $this->assertStringContainsString('./src/file.php', $notifications[0]['text']);
        $this->assertStringContainsString('offset: 42', $notifications[0]['text']);
    }
    /* ── Normalizer: empty content does not leak raw_result ── */

    /**
     * Test thesis: when a ToolCallResult has empty content but large
     * details.raw_result, the AgentMessageNormalizer must NOT JSON-encode
     * the full ToolCallResult envelope as model-facing text.  Doing so
     * duplicates raw_output into the model context, inflates the message
     * far beyond the tool's actual output, and triggers false late-hook
     * output capping (the root cause of the double-cap smoke bug).
     */
    public function testEmptyContentDoesNotExposeRawResultToModelText(): void
    {
        $normalizer = new AgentMessageNormalizer();

        $sentinel = 'RAW_SENTINEL_'.bin2hex(random_bytes(8));
        $largeRaw = str_repeat('Z', 1000).$sentinel;

        $result = new ToolCallResult(
            runId: 'r1',
            turnNo: 1,
            stepId: 's1',
            attempt: 1,
            idempotencyKey: 'ik1',
            toolCallId: 'call-empty-content',
            orderIndex: 0,
            result: [
                'tool_name' => 'read',
                'content' => [],
                'details' => ['raw_result' => $largeRaw],
            ],
            isError: false,
        );

        $message = $normalizer->toolMessage($result);

        // The model-facing text must NOT contain the raw sentinel.
        $contentText = '';
        foreach ($message->content as $part) {
            if (\is_array($part) && ($part['type'] ?? null) === 'text') {
                $contentText .= $part['text'];
            }
        }

        $this->assertStringNotContainsString($sentinel, $contentText,
            'Sentinel from details.raw_result must not leak into model-facing content text');

        // The model-facing text should be a compact label like 'read completed'.
        $this->assertStringContainsString('read', $contentText);
        $this->assertStringNotContainsString('RAW_SENTINEL_', $contentText);

        // details are preserved on the AgentMessage for persistence
        $this->assertSame($largeRaw, $message->details['details']['raw_result'] ?? null,
            'Raw result must be preserved in AgentMessage details for persistence');
    }

    /* ── Helpers ── */

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }

        @rmdir($dir);
    }
}
