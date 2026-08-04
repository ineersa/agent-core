<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\CodingAgent\Extension\ExtensionToolControlFlagToolResultProcessor;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: extension-owned cancelled/timed_out raw maps get control flags promoted into
 * details for generic ToolExecutor arbitration, while identical built-in maps stay
 * untouched; timed_out + concurrent run cancel also marks cancelled without kind=interrupt.
 */
final class ExtensionToolControlFlagToolResultProcessorTest extends TestCase
{
    public function testPromotesControlFlagsOnlyForExtensionOwnedTools(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->dummyHandler();
        $registry->registerTool(
            name: 'ext_tool',
            description: 'Extension tool',
            parametersJsonSchema: ['type' => 'object'],
            handler: $handler,
            promptLine: 'ext_tool: Extension tool',
            extensionOwnerClass: 'Ineersa\\Fake\\ExtensionOwner',
        );
        $registry->registerTool(
            name: 'bash',
            description: 'Built-in tool',
            parametersJsonSchema: ['type' => 'object'],
            handler: $handler,
            promptLine: 'bash: Built-in tool',
        );

        $processor = new ExtensionToolControlFlagToolResultProcessor($registry);
        $raw = [
            'cancelled' => true,
            'timed_out' => true,
            'timeout_seconds' => 12,
            'message' => 'Stopped by handler.',
        ];

        $extensionResult = $processor->process(
            $this->toolResult('ext_tool', $raw),
            $this->toolCall('ext_tool'),
        );
        $builtinResult = $processor->process(
            $this->toolResult('bash', $raw),
            $this->toolCall('bash'),
        );

        $extDetails = $extensionResult->details;
        $this->assertIsArray($extDetails);
        $this->assertTrue($extDetails['cancelled'] ?? false);
        $this->assertTrue($extDetails['timed_out'] ?? false);
        $this->assertSame($raw, $extDetails['raw_result'] ?? null);
        $this->assertArrayNotHasKey('timeout_seconds', $extDetails);
        $this->assertArrayNotHasKey('message', $extDetails);
        $this->assertArrayNotHasKey('kind', $extDetails);
        $this->assertFalse($extensionResult->isError);
        $this->assertSame('handler body', $extensionResult->content[0]['text'] ?? null);

        $builtinDetails = $builtinResult->details;
        $this->assertIsArray($builtinDetails);
        $this->assertArrayNotHasKey('cancelled', $builtinDetails);
        $this->assertArrayNotHasKey('timed_out', $builtinDetails);
        $this->assertSame($raw, $builtinDetails['raw_result'] ?? null);
    }

    public function testTimedOutPlusRunCancelMarksCancelledWithoutInterruptKind(): void
    {
        $registry = new ToolRegistry();
        $registry->registerTool(
            name: 'ext_tool',
            description: 'Extension tool',
            parametersJsonSchema: ['type' => 'object'],
            handler: $this->dummyHandler(),
            promptLine: 'ext_tool: Extension tool',
            extensionOwnerClass: 'Ineersa\\Fake\\ExtensionOwner',
        );

        $token = new class implements CancellationTokenInterface {
            public function isCancellationRequested(): bool
            {
                return true;
            }
        };

        $raw = [
            'timed_out' => true,
            'timeout_seconds' => 5,
            'message' => 'Deadline hit during cancel race.',
        ];

        $processed = (new ExtensionToolControlFlagToolResultProcessor($registry))->process(
            $this->toolResult('ext_tool', $raw),
            $this->toolCall('ext_tool', ['cancel_token' => $token]),
        );

        $details = $processed->details;
        $this->assertIsArray($details);
        $this->assertTrue($details['timed_out'] ?? false);
        $this->assertTrue($details['cancelled'] ?? false);
        $this->assertSame($raw, $details['raw_result'] ?? null);
        $this->assertArrayNotHasKey('kind', $details);
        $this->assertArrayNotHasKey('stale_due_to_cancel', $details);
        $this->assertArrayNotHasKey('timeout_seconds', $details);
        $this->assertFalse($processed->isError);
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function toolResult(string $toolName, array $raw): ToolResult
    {
        return new ToolResult(
            toolCallId: 'call-1',
            toolName: $toolName,
            content: [['type' => 'text', 'text' => 'handler body']],
            details: [
                'raw_result' => $raw,
                'mode' => 'sequential',
            ],
            isError: false,
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private function toolCall(string $toolName, array $context = []): ToolCall
    {
        return new ToolCall(
            toolCallId: 'call-1',
            toolName: $toolName,
            arguments: [],
            orderIndex: 0,
            context: $context,
        );
    }

    private function dummyHandler(): ToolHandlerInterface
    {
        return new class implements ToolHandlerInterface {
            public function __invoke(array $arguments): mixed
            {
                return null;
            }
        };
    }
}
