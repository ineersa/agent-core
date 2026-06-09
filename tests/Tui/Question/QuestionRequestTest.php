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

        self::assertSame('req-1', $request->requestId);
        self::assertSame(QuestionSource::Tui, $request->source);
        self::assertSame(QuestionKind::Text, $request->kind);
        self::assertSame('What is your name?', $request->prompt);
        self::assertSame(['type' => 'string'], $request->schema);
        self::assertSame([], $request->choices);
        self::assertNull($request->default);
        self::assertNull($request->header);
        self::assertTrue($request->allowOther);
        self::assertFalse($request->secret);
        self::assertNull($request->runId);
        self::assertNull($request->questionId);
        self::assertNull($request->toolCallId);
        self::assertNull($request->toolName);
        self::assertFalse($request->transcript);
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
            secret: false,
            runId: 'run-123',
            questionId: 'q-456',
            toolCallId: 'tc-789',
            toolName: 'ask_human',
            transcript: true,
        );

        self::assertSame('req-2', $request->requestId);
        self::assertSame(QuestionSource::AgentCore, $request->source);
        self::assertSame(QuestionKind::Choice, $request->kind);
        self::assertSame('Which strategy?', $request->prompt);
        self::assertSame(['type' => 'string', 'enum' => ['simple', 'robust']], $request->schema);
        self::assertCount(2, $request->choices);
        self::assertSame('simple', $request->choices[0]->label);
        self::assertSame('Fast, minimal change', $request->choices[0]->description);
        self::assertSame('robust', $request->choices[1]->label);
        self::assertSame('More complete implementation', $request->choices[1]->description);
        self::assertSame('simple', $request->default);
        self::assertSame('Choose Strategy', $request->header);
        self::assertFalse($request->allowOther);
        self::assertFalse($request->secret);
        self::assertSame('run-123', $request->runId);
        self::assertSame('q-456', $request->questionId);
        self::assertSame('tc-789', $request->toolCallId);
        self::assertSame('ask_human', $request->toolName);
        self::assertTrue($request->transcript);
    }


}
