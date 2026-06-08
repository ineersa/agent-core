<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
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
