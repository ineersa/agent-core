<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\ToolQuestion;

use Ineersa\CodingAgent\Entity\ToolQuestion;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for ToolQuestion::create() factory validation.
 *
 * These tests exercise only the static factory method and do NOT
 * require a Symfony kernel or Doctrine. Extracted from the heavier
 * ToolQuestionStoreTest to reduce kernel boot overhead.
 *
 * @covers \Ineersa\CodingAgent\Entity\ToolQuestion
 */
#[CoversClass(ToolQuestion::class)]
final class ToolQuestionFactoryTest extends TestCase
{
    // ── Entity factory input validation ──

    public function testCreateWithEmptyRequestIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requestId must not be empty');

        ToolQuestion::create(
            requestId: '',
            runId: 'run-1',
            toolCallId: 'tc-1',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: 'test',
            prompt: 'Test prompt?',
        );
    }

    public function testCreateWithWhitespaceOnlyRequestIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('requestId must not be empty');

        ToolQuestion::create(
            requestId: "   \t\n",
            runId: 'run-1',
            toolCallId: 'tc-1',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: 'test',
            prompt: 'Test prompt?',
        );
    }

    public function testCreateWithOverlongCommandPreviewThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('commandPreview must not exceed 200 characters');

        ToolQuestion::create(
            requestId: 'test-valid',
            runId: 'run-1',
            toolCallId: 'tc-1',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: str_repeat('x', 201),
            prompt: 'Test prompt?',
        );
    }

    public function testCreateWithExactlyMaxLengthCommandPreviewSucceeds(): void
    {
        $q = ToolQuestion::create(
            requestId: 'test-max-preview',
            runId: 'run-1',
            toolCallId: 'tc-1',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: str_repeat('x', 200),
            prompt: 'Test prompt?',
        );

        $this->assertSame('test-max-preview', $q->requestId);
        $this->assertSame(200, mb_strlen($q->commandPreview));
    }

    public function testCreateWithEmptyRunIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('runId must not be empty');

        ToolQuestion::create(
            requestId: 'rq-1',
            runId: '',
            toolCallId: 'tc-1',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: 'test',
            prompt: 'Test prompt?',
        );
    }

    public function testCreateWithEmptyToolCallIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('toolCallId must not be empty');

        ToolQuestion::create(
            requestId: 'rq-1',
            runId: 'run-1',
            toolCallId: '',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: 'test',
            prompt: 'Test prompt?',
        );
    }

    public function testCreateWithEmptyToolNameThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('toolName must not be empty');

        ToolQuestion::create(
            requestId: 'rq-1',
            runId: 'run-1',
            toolCallId: 'tc-1',
            toolName: '',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: 'test',
            prompt: 'Test prompt?',
        );
    }

    public function testCreateWithEmptyPromptThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('prompt must not be empty');

        ToolQuestion::create(
            requestId: 'rq-1',
            runId: 'run-1',
            toolCallId: 'tc-1',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: 'test',
            prompt: '',
        );
    }

    public function testCreateWithEmptyKindThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('kind must not be empty');

        ToolQuestion::create(
            requestId: 'rq-1',
            runId: 'run-1',
            toolCallId: 'tc-1',
            toolName: 'bash',
            pid: 12345,
            logPath: '/tmp/test.log',
            commandPreview: 'test',
            prompt: 'Test prompt?',
            kind: '',
        );
    }
}
