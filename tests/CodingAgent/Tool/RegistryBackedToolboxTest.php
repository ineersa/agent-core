<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Tool\HatfieldToolProviderInterface;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolDefinitionDTO;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Event\ToolCallArgumentsResolved;
use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\EventDispatcher\EventDispatcher;

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

    public function testExecuteDispatchesSymfonyAiToolLifecycleEvents(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->dummyHandler('evented result');
        $registry->registerTool(name: 'evented', description: 'Evented', parametersJsonSchema: [], handler: $handler, promptLine: 'evented');

        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(ToolCallRequested::class, function (ToolCallRequested $event) use (&$events): void {
            $events[] = ['requested', $event->getToolCall()->getName(), $event->getMetadata()->getName()];
        });
        $dispatcher->addListener(ToolCallArgumentsResolved::class, function (ToolCallArgumentsResolved $event) use (&$events, $handler): void {
            $events[] = ['arguments_resolved', $event->getTool() === $handler, $event->getArguments()];
        });
        $dispatcher->addListener(ToolCallSucceeded::class, function (ToolCallSucceeded $event) use (&$events, $handler): void {
            $events[] = ['succeeded', $event->getTool() === $handler, $event->getResult()->getResult()];
        });

        $toolbox = new RegistryBackedToolbox($registry, $dispatcher);
        $result = $toolbox->execute(new ToolCall('call-events', 'evented', ['query' => 'hello']));

        self::assertSame('evented result', $result->getResult());
        self::assertSame([
            ['requested', 'evented', 'evented'],
            ['arguments_resolved', true, ['query' => 'hello']],
            ['succeeded', true, 'evented result'],
        ], $events);
    }

    public function testToolCallRequestedCanDenyAndSkipHandler(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool(name: 'guarded', description: 'Guarded', parametersJsonSchema: [], handler: $handler, promptLine: 'guarded');

        $dispatcher = new EventDispatcher();
        $events = [];
        $dispatcher->addListener(ToolCallRequested::class, function (ToolCallRequested $event) use (&$events): void {
            $events[] = 'requested';
            $event->deny('blocked by listener');
        });
        $dispatcher->addListener(ToolCallArgumentsResolved::class, static function () use (&$events): void {
            $events[] = 'arguments_resolved';
        });

        $toolbox = new RegistryBackedToolbox($registry, $dispatcher);
        $result = $toolbox->execute(new ToolCall('call-denied', 'guarded', []));

        self::assertSame('blocked by listener', $result->getResult());
        self::assertSame(0, $handler->calls);
        self::assertSame(['requested'], $events);
    }

    public function testToolCallRequestedCanReplaceResultAndSkipHandler(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool(name: 'replaceable', description: 'Replaceable', parametersJsonSchema: [], handler: $handler, promptLine: 'replaceable');

        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ToolCallRequested::class, static function (ToolCallRequested $event): void {
            $event->setResult(new ToolResult($event->getToolCall(), ['replaced' => true]));
        });

        $toolbox = new RegistryBackedToolbox($registry, $dispatcher);
        $result = $toolbox->execute(new ToolCall('call-replaced', 'replaceable', []));

        self::assertSame(['replaced' => true], $result->getResult());
        self::assertSame(0, $handler->calls);
    }

    public function testExecuteDispatchesSymfonyAiToolFailedEvent(): void
    {
        $registry = new ToolRegistry();
        $exception = new \RuntimeException('boom');
        $handler = new class($exception) implements ToolHandlerInterface {
            public function __construct(
                private readonly \RuntimeException $exception,
            ) {
            }

            public function __invoke(array $arguments): mixed
            {
                throw $this->exception;
            }
        };
        $registry->registerTool(name: 'failing', description: 'Failing', parametersJsonSchema: [], handler: $handler, promptLine: 'failing');

        $dispatcher = new EventDispatcher();
        $failedEvent = null;
        $dispatcher->addListener(ToolCallFailed::class, static function (ToolCallFailed $event) use (&$failedEvent): void {
            $failedEvent = $event;
        });

        $toolbox = new RegistryBackedToolbox($registry, $dispatcher);

        try {
            $toolbox->execute(new ToolCall('call-failed', 'failing', ['path' => 'x']));
            self::fail('Expected handler exception to be re-thrown.');
        } catch (\RuntimeException $caught) {
            self::assertSame($exception, $caught);
        }

        self::assertInstanceOf(ToolCallFailed::class, $failedEvent);
        self::assertSame($handler, $failedEvent->getTool());
        self::assertSame('failing', $failedEvent->getMetadata()->getName());
        self::assertSame(['path' => 'x'], $failedEvent->getArguments());
        self::assertSame($exception, $failedEvent->getException());
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

    private function countingHandler(mixed $result): ToolHandlerInterface
    {
        return new class($result) implements ToolHandlerInterface {
            public int $calls = 0;

            public function __construct(
                private readonly mixed $result,
            ) {
            }

            public function __invoke(array $arguments): mixed
            {
                ++$this->calls;

                return $this->result;
            }
        };
    }
}
