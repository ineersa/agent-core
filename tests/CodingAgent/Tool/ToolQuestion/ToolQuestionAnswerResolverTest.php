<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\ToolQuestion;

use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionAnswerResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ToolQuestionAnswerResolver::class)]
final class ToolQuestionAnswerResolverTest extends TestCase
{
    private ToolQuestionAnswerResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new ToolQuestionAnswerResolver();
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function provideTrueAnswers(): iterable
    {
        yield 'bool true' => [true, true];
        yield 'string yes' => ['yes', true];
        yield 'string YES' => ['YES', true];
        yield 'string Yes' => ['Yes', true];
        yield 'string true' => ['true', true];
        yield 'string TRUE' => ['TRUE', true];
        yield 'string 1' => ['1', true];
        yield 'int 1' => [1, true];
        yield 'string yes trimmed' => ['  yes  ', true];
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function provideFalseAnswers(): iterable
    {
        yield 'bool false' => [false, false];
        yield 'string no' => ['no', false];
        yield 'string NO' => ['NO', false];
        yield 'string No' => ['No', false];
        yield 'string false' => ['false', false];
        yield 'string 0' => ['0', false];
        yield 'int 0' => [0, false];
        yield 'string no trimmed' => ['  no  ', false];
        yield 'null' => [null, false];
        yield 'empty string' => ['', false];
        yield 'string unknown' => ['maybe', false];
        yield 'array' => [[], false];
        yield 'float' => [1.0, false];
        yield 'string random' => ['cancel', false];
    }

    #[DataProvider('provideTrueAnswers')]
    public function testResolvesTrue(mixed $input, bool $expected): void
    {
        self::assertSame($expected, $this->resolver->resolve($input));
    }

    #[DataProvider('provideFalseAnswers')]
    public function testResolvesFalse(mixed $input, bool $expected): void
    {
        self::assertSame($expected, $this->resolver->resolve($input));
    }
}
