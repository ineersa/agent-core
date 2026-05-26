<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Contract\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCancelledException;
use PHPUnit\Framework\TestCase;

final class ToolCancelledExceptionTest extends TestCase
{
    public function testExceptionIsRuntimeException(): void
    {
        $exception = new ToolCancelledException();

        self::assertInstanceOf(\RuntimeException::class, $exception);
    }

    public function testDefaultMessage(): void
    {
        $exception = new ToolCancelledException();

        self::assertSame('Tool execution cancelled by request.', $exception->getMessage());
    }

    public function testCustomMessage(): void
    {
        $exception = new ToolCancelledException('Cancelled by user.');

        self::assertSame('Cancelled by user.', $exception->getMessage());
    }
}
