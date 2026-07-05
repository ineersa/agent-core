<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Compaction\CompactionTokenEstimator;
use Ineersa\CodingAgent\Compaction\ToolResultDigestService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolResultDigestService::class)]
#[CoversClass(CompactionTokenEstimator::class)]
final class ToolResultDigestServiceTest extends TestCase
{
    private ToolResultDigestService $digestService;
    private CompactionTokenEstimator $tokenEstimator;

    protected function setUp(): void
    {
        $this->tokenEstimator = new CompactionTokenEstimator();
        $this->digestService = new ToolResultDigestService($this->tokenEstimator);
    }

    /**
     * A deterministic tool-result digest must preserve the actionable
     * fields requested in PR #178 review comments:
     *   - [tool output elided before summarization] banner
     *   - tool name, tool_call_id
     *   - command (when present in details)
     *   - exit_code / status (exit_code normalized from numeric string to int)
     *   - estimated_tokens, char_count
     *   - full_output blob path (when present in details)
     *   - important_lines_detected (FAIL/ERROR/Exception/file:line patterns)
     *   - preview_start / preview_end
     * …while never mutating the original message and retaining
     * toolCallId / toolName on the digest message.
     */
    public function testDigestToolResultContainsAllRequiredFields(): void
    {
        // ── Build a tool message with rich details ──
        $originalContent = implode("\n", [
            str_repeat('A', 100), // filler before important content
            'Fatal error: Call to undefined method Foo::bar() in src/Service/FooService.php:42',
            str_repeat('B', 1200), // body content to trigger preview split (> PREVIEW_LENGTH * 2 = 1000)
            'Traceback (most recent call last):',
        ]);

        $original = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => $originalContent]],
            toolCallId: 'call_abc123',
            toolName: 'bash',
            isError: true,
            details: [
                'command' => 'vendor/bin/phpunit --filter=FooTest',
                'exit_code' => '2', // numeric string — must normalize to int for status
                'blob_path' => '/tmp/blobs/abc123.output',
            ],
        );

        // ── Digest ──
        $messages = $this->digestService->digestToolResults([$original]);

        // Original message must not be mutated.
        $this->assertCount(1, $messages);
        $this->assertNotSame($original, $messages[0]);
        $this->assertSame('tool', $original->role);
        $this->assertSame('bash', $original->toolName);
        $this->assertSame('call_abc123', $original->toolCallId);
        $this->assertTrue($original->isError);

        // Digest message must preserve tool identity.
        $digest = $messages[0];
        $this->assertSame('tool', $digest->role);
        $this->assertSame('bash', $digest->toolName);
        $this->assertSame('call_abc123', $digest->toolCallId);

        $digestText = self::assertSingleTextContent($digest);

        // ── Assert required digest fields ──
        $this->assertStringContainsString('[tool output elided before summarization]', $digestText);
        $this->assertStringContainsString('tool: bash', $digestText);
        $this->assertStringContainsString('tool_call_id: call_abc123', $digestText);
        $this->assertStringContainsString('command: vendor/bin/phpunit --filter=FooTest', $digestText);
        // exit_code normalized from string '2' to int 2
        $this->assertStringContainsString('exit_code: 2', $digestText);
        // status reflects non-zero exit code
        $this->assertStringContainsString('status: exit code 2', $digestText);
        $this->assertStringContainsString('estimated_tokens: ~', $digestText);
        $this->assertStringContainsString('char_count:', $digestText);
        $this->assertStringContainsString('full_output: /tmp/blobs/abc123.output', $digestText);
        $this->assertStringContainsString('important_lines_detected:', $digestText);
        $this->assertStringContainsString('src/Service/FooService.php:42', $digestText);
        $this->assertStringContainsString('Fatal error', $digestText);
        // preview_start and preview_end (content > 1000 chars triggers split)
        $this->assertStringContainsString('preview_start:', $digestText);
        $this->assertStringContainsString('preview_end:', $digestText);
    }

    /**
     * Numeric-string exit_code '0' must not be reported as a non-zero
     * exit code. The exit_code field should show 0 and status must NOT
     * say "exit code 0".
     */
    public function testStringZeroExitCodeNormalizedToInt(): void
    {
        $original = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => 'All tests passed.']],
            toolCallId: 'call_zero',
            toolName: 'phpunit',
            details: [
                'command' => 'vendor/bin/phpunit',
                'exit_code' => '0', // string zero
            ],
        );

        $messages = $this->digestService->digestToolResults([$original]);
        $digest = $messages[0];
        $digestText = self::assertSingleTextContent($digest);

        $this->assertStringContainsString('exit_code: 0', $digestText);
        // Must NOT say "exit code 0" — zero exit is normal.
        $this->assertStringNotContainsString('status: exit code 0', $digestText);
        // Should report status as ok (not ERROR, since isError is false by default).
        $this->assertStringContainsString('status: ok', $digestText);
    }

    /**
     * Non-tool messages pass through unchanged.
     */
    public function testNonToolMessagesPassthrough(): void
    {
        $user = new AgentMessage(
            role: 'user',
            content: [['type' => 'text', 'text' => 'Hello']],
        );

        $assistant = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'Hi there']],
        );

        $messages = $this->digestService->digestToolResults([$user, $assistant]);

        $this->assertCount(2, $messages);
        $this->assertSame($user, $messages[0]);
        $this->assertSame($assistant, $messages[1]);
    }

    /**
     * Extract the single text part from a digest message and return it
     * as a string.
     */
    private static function assertSingleTextContent(AgentMessage $message): string
    {
        $content = $message->content;
        self::assertIsArray($content);
        self::assertCount(1, $content);
        self::assertIsArray($content[0]);
        self::assertSame('text', $content[0]['type'] ?? null);
        self::assertIsString($content[0]['text'] ?? null);

        return $content[0]['text'];
    }
}
