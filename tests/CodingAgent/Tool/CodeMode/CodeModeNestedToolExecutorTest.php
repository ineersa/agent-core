<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Domain\Tool\ToolResultText;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeNestedToolExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult as ToolboxResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class CodeModeNestedToolExecutorTest extends TestCase
{
    private const array CONTENT = [['type' => 'text', 'text' => 'presentation']];

    public function testExplicitNullDoesNotFallBackToPresentation(): void
    {
        $this->assertNull($this->execute(new ToolResult('nested-call', 'fixture', self::CONTENT, ['raw_result' => null])));
    }

    public function testProgrammaticValuePreservesNullAndAttachmentShapedData(): void
    {
        foreach ([null, ['attachment_refs' => [['type' => 'image_ref', 'path' => 'opaque-data']]]] as $value) {
            $this->assertSame($value, $this->execute(new ToolResult('nested-call', 'fixture', self::CONTENT, programmaticResult: [$value])));
        }
    }

    public function testTypedResultWithoutRawValueReturnsContentBlocks(): void
    {
        $this->assertSame(self::CONTENT, $this->execute(new ToolResult('nested-call', 'fixture', self::CONTENT)));
    }

    public function testNativeArrayIsNotInterpretedAsTypedResult(): void
    {
        $value = ['raw_result' => null, 'isError' => true, 'items' => [1, false]];

        $this->assertSame($value, $this->execute($value));
    }

    #[DataProvider('rejectedTypedResults')]
    public function testInvalidTypedResultThrowsToolCallException(ToolResult $value, string $message): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage($message);

        $this->execute($value);
    }

    /** @return iterable<string, array{ToolResult, string}> */
    public static function rejectedTypedResults(): iterable
    {
        $identityError = 'Typed tool result identity does not match its invocation.';
        yield 'wrong call id' => [new ToolResult('different-call', 'fixture', self::CONTENT), $identityError];
        yield 'wrong tool name' => [new ToolResult('nested-call', 'different-tool', self::CONTENT), $identityError];
        yield 'reported error despite raw value' => [new ToolResult('nested-call', 'fixture', self::CONTENT, ['raw_result' => 42], isError: true), 'Nested tool returned an error.'];
        yield 'malformed content despite safe raw value' => [new ToolResult('nested-call', 'fixture', [['type' => 'text', 'text' => "\xFF"]], ['raw_result' => 42]), ToolResultText::FAILURE_MESSAGE];

        $opaque = new class implements \JsonSerializable, \Stringable {
            public function jsonSerialize(): never
            {
                throw new \LogicException('Opaque tool data must not be serialized.');
            }

            public function __toString(): string
            {
                throw new \LogicException('Opaque tool data must not be stringified.');
            }
        };
        yield 'opaque programmatic data' => [new ToolResult('nested-call', 'fixture', self::CONTENT, programmaticResult: [$opaque]), ToolResultText::INSPECTION_FAILURE_MESSAGE];
        yield 'opaque details' => [new ToolResult('nested-call', 'fixture', self::CONTENT, ['raw_result' => 42, 'opaque' => $opaque]), ToolResultText::INSPECTION_FAILURE_MESSAGE];
    }

    private function execute(mixed $value): mixed
    {
        $toolbox = $this->createMock(ToolboxInterface::class);
        $toolbox->expects($this->once())->method('execute')->willReturnCallback(
            static fn (ToolCall $call): ToolboxResult => new ToolboxResult($call, $value),
        );
        $executor = new CodeModeNestedToolExecutor(
            new StackToolExecutionContextAccessor(),
            new ServiceLocator(['toolbox' => static fn () => $toolbox]),
        );

        return $executor->execute('nested-call', 'fixture', [], null, new NullCancellationToken());
    }
}
