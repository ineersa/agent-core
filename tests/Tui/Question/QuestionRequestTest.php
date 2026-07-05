<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Question;

use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionOption;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(QuestionRequest::class)]
final class QuestionRequestTest extends TestCase
{
    public function testMinimalConstruction(): void
    {
        $request = new QuestionRequest(
            requestId: 'req-1',
            source: QuestionSource::Tui,
            kind: QuestionKind::Text,
            prompt: 'What is your name?',
        );

        $this->assertSame('req-1', $request->requestId);
        $this->assertSame(QuestionSource::Tui, $request->source);
        $this->assertSame(QuestionKind::Text, $request->kind);
        $this->assertSame('What is your name?', $request->prompt);
        $this->assertSame(['type' => 'string'], $request->schema);
        $this->assertSame([], $request->choices);
        $this->assertNull($request->default);
        $this->assertNull($request->header);
        $this->assertTrue($request->allowOther);
        $this->assertNull($request->runId);
        $this->assertNull($request->questionId);
        $this->assertNull($request->toolCallId);
        $this->assertNull($request->toolName);
        $this->assertFalse($request->transcript);
    }

    public function testFullConstruction(): void
    {
        $choices = [
            new QuestionOption('simple', 'Fast, minimal change'),
            new QuestionOption('robust', 'More complete implementation'),
        ];

        $request = new QuestionRequest(
            requestId: 'req-2',
            source: QuestionSource::AgentCore,
            kind: QuestionKind::Choice,
            prompt: 'Which strategy?',
            schema: ['type' => 'string', 'enum' => ['simple', 'robust']],
            choices: $choices,
            default: 'simple',
            header: 'Choose Strategy',
            allowOther: false,
            runId: 'run-123',
            questionId: 'q-456',
            toolCallId: 'tc-789',
            toolName: 'ask_human',
            transcript: true,
        );

        $this->assertSame('req-2', $request->requestId);
        $this->assertSame(QuestionSource::AgentCore, $request->source);
        $this->assertSame(QuestionKind::Choice, $request->kind);
        $this->assertSame('Which strategy?', $request->prompt);
        $this->assertSame(['type' => 'string', 'enum' => ['simple', 'robust']], $request->schema);
        $this->assertCount(2, $request->choices);
        $this->assertSame('simple', $request->choices[0]->label);
        $this->assertSame('Fast, minimal change', $request->choices[0]->description);
        $this->assertSame('robust', $request->choices[1]->label);
        $this->assertSame('More complete implementation', $request->choices[1]->description);
        $this->assertSame('simple', $request->default);
        $this->assertSame('Choose Strategy', $request->header);
        $this->assertFalse($request->allowOther);
        $this->assertSame('run-123', $request->runId);
        $this->assertSame('q-456', $request->questionId);
        $this->assertSame('tc-789', $request->toolCallId);
        $this->assertSame('ask_human', $request->toolName);
        $this->assertTrue($request->transcript);
    }
}
