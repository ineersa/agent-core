<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Contract\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\AgentCore\Contract\Tool\ToolCallException
 */
final class ToolCallExceptionTest extends TestCase
{
    public function testConstructWithErrorOnly(): void
    {
        $e = new ToolCallException('Something went wrong');

        self::assertSame('Something went wrong', $e->getMessage());
        self::assertFalse($e->retryable());
        self::assertNull($e->hint());
        self::assertSame(0, $e->getCode());
        self::assertNull($e->getPrevious());
    }

    public function testConstructWithRetryableTrue(): void
    {
        $e = new ToolCallException('Retryable error', retryable: true);

        self::assertTrue($e->retryable());
    }

    public function testConstructWithHint(): void
    {
        $e = new ToolCallException('Missing argument', hint: 'Provide a valid file path.');

        self::assertSame('Missing argument', $e->getMessage());
        self::assertSame('Provide a valid file path.', $e->hint());
    }

    public function testConstructWithPrevious(): void
    {
        $previous = new \RuntimeException('Root cause');
        $e = new ToolCallException('Wrapper error', previous: $previous);

        self::assertSame($previous, $e->getPrevious());
    }

    public function testIsRuntimeExceptionSubclass(): void
    {
        $e = new ToolCallException('error');

        self::assertInstanceOf(\RuntimeException::class, $e);
    }

    public function testIsThrowable(): void
    {
        $e = new ToolCallException('error');

        self::assertInstanceOf(\Throwable::class, $e);
    }

    public function testAllParametersTogether(): void
    {
        $previous = new \LogicException('root');
        $e = new ToolCallException(
            'Complex error',
            retryable: true,
            hint: 'Check input',
            previous: $previous,
        );

        self::assertSame('Complex error', $e->getMessage());
        self::assertTrue($e->retryable());
        self::assertSame('Check input', $e->hint());
        self::assertSame($previous, $e->getPrevious());
    }
}
