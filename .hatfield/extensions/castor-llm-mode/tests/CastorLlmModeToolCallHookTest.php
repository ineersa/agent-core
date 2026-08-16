<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\CastorLlmMode\Tests;

use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\HatfieldExt\CastorLlmMode\CastorCommandRewriter;
use Ineersa\HatfieldExt\CastorLlmMode\CastorLlmModeToolCallHook;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CastorLlmModeToolCallHookTest extends TestCase
{
    private CastorLlmModeToolCallHook $hook;

    protected function setUp(): void
    {
        $this->hook = new CastorLlmModeToolCallHook(new CastorCommandRewriter());
    }

    #[Test]
    public function nonBashToolReturnsNull(): void
    {
        $context = new ToolCallContextDTO(
            toolCallId: 'tc1',
            toolName: 'read',
            arguments: ['command' => 'castor list'],
            orderIndex: 0,
        );

        $this->assertNull($this->hook->rewriteArguments($context));
    }

    #[Test]
    public function bashNonCastorReturnsNull(): void
    {
        $context = new ToolCallContextDTO(
            toolCallId: 'tc1',
            toolName: 'bash',
            arguments: ['arguments' => ['command' => 'ls -la']],
            orderIndex: 0,
        );

        $this->assertNull($this->hook->rewriteArguments($context));
    }

    #[Test]
    public function bashCastorRewritesCommand(): void
    {
        $context = new ToolCallContextDTO(
            toolCallId: 'tc1',
            toolName: 'bash',
            arguments: ['arguments' => ['command' => 'castor list']],
            orderIndex: 0,
        );

        $result = $this->hook->rewriteArguments($context);

        $this->assertIsArray($result);
        // Rewritten arguments keep the native nested envelope.
        $this->assertArrayHasKey('arguments', $result);
        $this->assertArrayHasKey('command', $result['arguments']);
        $command = $result['arguments']['command'];
        $this->assertIsString($command);
        $this->assertStringStartsWith('export LLM_MODE=true', $command);
        $this->assertStringContainsString('--format=md --short --no-ansi', $command);
    }

    #[Test]
    public function missingCommandReturnsNull(): void
    {
        $context = new ToolCallContextDTO(
            toolCallId: 'tc1',
            toolName: 'bash',
            arguments: ['arguments' => []],
            orderIndex: 0,
        );

        $this->assertNull($this->hook->rewriteArguments($context));
    }

    #[Test]
    public function nonStringCommandReturnsNull(): void
    {
        $context = new ToolCallContextDTO(
            toolCallId: 'tc1',
            toolName: 'bash',
            arguments: ['arguments' => ['command' => 42]],
            orderIndex: 0,
        );

        $this->assertNull($this->hook->rewriteArguments($context));
    }

    #[Test]
    public function flatBashCallIsMalformedAndNotRewritten(): void
    {
        // No backward-compat shim: a non-enveloped bash call is malformed
        // under the typed built-in contract and must not be rewritten.
        $context = new ToolCallContextDTO(
            toolCallId: 'tc1',
            toolName: 'bash',
            arguments: ['command' => 'castor list'],
            orderIndex: 0,
        );

        $this->assertNull($this->hook->rewriteArguments($context));
    }
}
