<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Platform\Result\ToolCall;

/**
 * Tests for RegistryBackedToolbox.
 *
 * Covers definition-to-Symfony-Tool conversion, handler invocation
 * for permanent, dynamic, and extension-registered tools, and the
 * tool-not-found path.
 */
final class RegistryBackedToolboxTest extends TestCase
{
    /* ───────── ToolboxInterface contract ───────── */

    public function testImplementsToolboxInterface(): void
    {
        $registry = new ToolRegistry();
        $toolbox = new RegistryBackedToolbox($registry);

        self::assertInstanceOf(ToolboxInterface::class, $toolbox);
    }

    /* ───────── getTools() ───────── */

    public function testGetToolsReturnsEmptyForEmptyRegistry(): void
    {
        $registry = new ToolRegistry();
        $toolbox = new RegistryBackedToolbox($registry);

        self::assertSame([], $toolbox->getTools());
    }

    public function testGetToolsConvertsPermanentToolsToSymfonyTools(): void
    {
        $handler = $this->dummyHandler('permanent result');
        $registry = new ToolRegistry();

        $registry->registerTool(
            name: 'read',
            description: 'Read file contents',
            parametersJsonSchema: ['type' => 'object', 'properties' => ['path' => ['type' => 'string']]],
            handler: $handler,
            promptLine: 'read: Read files',
            promptGuidelines: ['Use read for files'],
        );

        $toolbox = new RegistryBackedToolbox($registry);
        $tools = $toolbox->getTools();

        self::assertCount(1, $tools);
        self::assertSame('read', $tools[0]->getName());
        self::assertSame('Read file contents', $tools[0]->getDescription());
        self::assertSame(['type' => 'object', 'properties' => ['path' => ['type' => 'string']]], $tools[0]->getParameters());
        self::assertSame($handler::class, $tools[0]->getReference()->getClass());
        self::assertSame('__invoke', $tools[0]->getReference()->getMethod());
    }

    public function testGetToolsIncludesDynamicAfterPermanent(): void
    {
        $registry = new ToolRegistry();

        $registry->registerTool(
            name: 'perm',
            description: 'Permanent',
            parametersJsonSchema: [],
            handler: $this->dummyHandler('perm'),
            promptLine: 'perm',
        );
        $registry->addDynamicTool(
            name: 'dyn',
            description: 'Dynamic',
            parametersJsonSchema: [],
            handler: $this->dummyHandler('dyn'),
        );

        $toolbox = new RegistryBackedToolbox($registry);
        $tools = $toolbox->getTools();

        self::assertCount(2, $tools);
        self::assertSame('perm', $tools[0]->getName());
        self::assertSame('dyn', $tools[1]->getName());
    }

    public function testGetToolsPreservesOrder(): void
    {
        $registry = new ToolRegistry();

        $registry->registerTool(name: 'a', description: 'A', parametersJsonSchema: [], handler: $this->dummyHandler('a'), promptLine: 'a');
        $registry->registerTool(name: 'b', description: 'B', parametersJsonSchema: [], handler: $this->dummyHandler('b'), promptLine: 'b');
        $registry->registerTool(name: 'c', description: 'C', parametersJsonSchema: [], handler: $this->dummyHandler('c'), promptLine: 'c');

        $toolbox = new RegistryBackedToolbox($registry);
        $names = array_map(static fn ($t) => $t->getName(), $toolbox->getTools());

        self::assertSame(['a', 'b', 'c'], $names);
    }

    /* ───────── execute() ───────── */

    public function testExecuteCallsHandlerWithArguments(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->dummyHandler(['status' => 'ok', 'input' => 'worked']);

        $registry->registerTool(
            name: 'search',
            description: 'Search',
            parametersJsonSchema: [],
            handler: $handler,
            promptLine: 'search: Search',
        );

        $toolbox = new RegistryBackedToolbox($registry);
        $toolCall = new ToolCall('call-1', 'search', ['query' => 'hello']);

        $result = $toolbox->execute($toolCall);

        self::assertSame($toolCall, $result->getToolCall());
        self::assertSame(['status' => 'ok', 'input' => 'worked'], $result->getResult());
    }

    public function testExecuteForDynamicTool(): void
    {
        $registry = new ToolRegistry();

        $registry->addDynamicTool(
            name: 'fg_tool',
            description: 'Fg tool',
            parametersJsonSchema: [],
            handler: $this->dummyHandler('dynamic result'),
        );

        $toolbox = new RegistryBackedToolbox($registry);
        $result = $toolbox->execute(new ToolCall('call-2', 'fg_tool', []));

        self::assertSame('dynamic result', $result->getResult());
    }

    public function testExecuteForHandlerWithNoArguments(): void
    {
        $registry = new ToolRegistry();

        $handler = $this->dummyHandler('no-args result');
        $registry->registerTool(name: 'ping', description: 'Ping', parametersJsonSchema: [], handler: $handler, promptLine: 'ping: Ping');

        $toolbox = new RegistryBackedToolbox($registry);
        $result = $toolbox->execute(new ToolCall('call-3', 'ping', []));

        self::assertSame('no-args result', $result->getResult());
    }

    public function testExecuteThrowsToolNotFoundException(): void
    {
        $registry = new ToolRegistry();
        $toolbox = new RegistryBackedToolbox($registry);

        $this->expectException(ToolNotFoundException::class);
        $toolbox->execute(new ToolCall('call-4', 'nonexistent', []));
    }

    /* ───────── Extension-registered tools are the same path ───────── */

    public function testExecuteForExtensionRegisteredTool(): void
    {
        // Extension tools are registered through ExtensionToolRegistryBridge which
        // calls registerTool() on the same ToolRegistry. Verify the registry
        // path works with a direct registerTool() equivalent.
        $registry = new ToolRegistry();
        $handler = $this->dummyHandler('extension result');

        $registry->registerTool(
            name: 'ext_tool',
            description: 'Extension tool',
            parametersJsonSchema: [],
            handler: $handler,
            promptLine: 'ext_tool: Extension tool',
        );

        $toolbox = new RegistryBackedToolbox($registry);
        $result = $toolbox->execute(new ToolCall('call-5', 'ext_tool', ['foo' => 'bar']));

        self::assertSame('extension result', $result->getResult());
    }

    /* ───────── Private helpers ───────── */

    private function dummyHandler(mixed $result): ToolHandlerInterface
    {
        return new class($result) implements ToolHandlerInterface {
            public function __construct(
                private readonly mixed $result,
            ) {
            }

            public function __invoke(array $arguments): mixed
            {
                return $this->result;
            }
        };
    }
}
