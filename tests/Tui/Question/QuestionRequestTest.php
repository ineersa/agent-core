<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Question;

use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionOption;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuestionRequest::class)]
final class QuestionRequestTest extends TestCase
{
    #[Test]
    public function testMinimalConstruction(): void
    {
        $request = new QuestionRequest(
            requestId: 'req-1',
            source: QuestionSource::Tui,
            kind: QuestionKind::Text,
            prompt: 'Enter value'
        );

        $this->assertSame('req-1', $request->requestId);
        $this->assertSame(QuestionSource::Tui, $request->source);
        $this->assertSame(QuestionKind::Text, $request->kind);
        $this->assertSame('Enter value', $request->prompt);
        $this->assertSame([], $request->choices);
        $this->assertNull($request->header);
        $this->assertTrue($request->allowOther);
        $this->assertNull($request->runId);
        $this->assertNull($request->toolCallId);
        $this->assertNull($request->toolName);
    }

    #[Test]
    public function testFullConstruction(): void
    {
        $request = new QuestionRequest(
            requestId: 'req-2',
            source: QuestionSource::AgentCore,
            kind: QuestionKind::Choice,
            prompt: 'Pick one',
            choices: [new QuestionOption('simple'), new QuestionOption('robust')],
            header: 'Choose mode',
            allowOther: false,
            runId: 'run-123',
            toolCallId: 'tc-1',
            toolName: 'ask_human'
        );

        $this->assertSame('req-2', $request->requestId);
        $this->assertSame(QuestionSource::AgentCore, $request->source);
        $this->assertSame(QuestionKind::Choice, $request->kind);
        $this->assertSame('Pick one', $request->prompt);
        $this->assertCount(2, $request->choices);
        $this->assertSame('Choose mode', $request->header);
        $this->assertFalse($request->allowOther);
        $this->assertSame('run-123', $request->runId);
        $this->assertSame('tc-1', $request->toolCallId);
        $this->assertSame('ask_human', $request->toolName);
    }
}
