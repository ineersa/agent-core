<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookProviderInterface;
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

        $this->assertInstanceOf(ToolboxInterface::class, $toolbox);
    }

    /* ───────── getTools() ───────── */

    public function testGetToolsReturnsEmptyForEmptyRegistry(): void
    {
        $registry = new ToolRegistry();
        $toolbox = new RegistryBackedToolbox($registry);

        $this->assertSame([], $toolbox->getTools());
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

        $this->assertCount(1, $tools);
        $this->assertSame('read', $tools[0]->getName());
        $this->assertSame('Read file contents', $tools[0]->getDescription());
        $this->assertSame(['type' => 'object', 'properties' => ['path' => ['type' => 'string']]], $tools[0]->getParameters());
        $this->assertSame($handler::class, $tools[0]->getReference()->getClass());
        $this->assertSame('__invoke', $tools[0]->getReference()->getMethod());
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

        $this->assertCount(2, $tools);
        $this->assertSame('perm', $tools[0]->getName());
        $this->assertSame('dyn', $tools[1]->getName());
    }

    public function testGetToolsPreservesOrder(): void
    {
        $registry = new ToolRegistry();

        $registry->registerTool(name: 'a', description: 'A', parametersJsonSchema: [], handler: $this->dummyHandler('a'), promptLine: 'a');
        $registry->registerTool(name: 'b', description: 'B', parametersJsonSchema: [], handler: $this->dummyHandler('b'), promptLine: 'b');
        $registry->registerTool(name: 'c', description: 'C', parametersJsonSchema: [], handler: $this->dummyHandler('c'), promptLine: 'c');

        $toolbox = new RegistryBackedToolbox($registry);
        $names = array_map(static fn ($t) => $t->getName(), $toolbox->getTools());

        $this->assertSame(['a', 'b', 'c'], $names);
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

        $this->assertSame($toolCall, $result->getToolCall());
        $this->assertSame(['status' => 'ok', 'input' => 'worked'], $result->getResult());
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

        $this->assertSame('dynamic result', $result->getResult());
    }

    public function testExecuteForHandlerWithNoArguments(): void
    {
        $registry = new ToolRegistry();

        $handler = $this->dummyHandler('no-args result');
        $registry->registerTool(name: 'ping', description: 'Ping', parametersJsonSchema: [], handler: $handler, promptLine: 'ping: Ping');

        $toolbox = new RegistryBackedToolbox($registry);
        $result = $toolbox->execute(new ToolCall('call-3', 'ping', []));

        $this->assertSame('no-args result', $result->getResult());
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
        $dispatcher->addListener(ToolCallRequested::class, static function (ToolCallRequested $event) use (&$events): void {
            $events[] = ['requested', $event->getToolCall()->getName(), $event->getMetadata()->getName()];
        });
        $dispatcher->addListener(ToolCallArgumentsResolved::class, static function (ToolCallArgumentsResolved $event) use (&$events, $handler): void {
            $events[] = ['arguments_resolved', $event->getTool() === $handler, $event->getArguments()];
        });
        $dispatcher->addListener(ToolCallSucceeded::class, static function (ToolCallSucceeded $event) use (&$events, $handler): void {
            $events[] = ['succeeded', $event->getTool() === $handler, $event->getResult()->getResult()];
        });

        $toolbox = new RegistryBackedToolbox($registry, $dispatcher);
        $result = $toolbox->execute(new ToolCall('call-events', 'evented', ['query' => 'hello']));

        $this->assertSame('evented result', $result->getResult());
        $this->assertSame([
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
        $dispatcher->addListener(ToolCallRequested::class, static function (ToolCallRequested $event) use (&$events): void {
            $events[] = 'requested';
            $event->deny('blocked by listener');
        });
        $dispatcher->addListener(ToolCallArgumentsResolved::class, static function () use (&$events): void {
            $events[] = 'arguments_resolved';
        });

        $toolbox = new RegistryBackedToolbox($registry, $dispatcher);
        $result = $toolbox->execute(new ToolCall('call-denied', 'guarded', []));

        $this->assertSame('blocked by listener', $result->getResult());
        $this->assertSame(0, $handler->calls);
        $this->assertSame(['requested'], $events);
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

        $this->assertSame(['replaced' => true], $result->getResult());
        $this->assertSame(0, $handler->calls);
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
            $this->fail('Expected handler exception to be re-thrown.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertInstanceOf(ToolCallFailed::class, $failedEvent);
        $this->assertSame($handler, $failedEvent->getTool());
        $this->assertSame('failing', $failedEvent->getMetadata()->getName());
        $this->assertSame(['path' => 'x'], $failedEvent->getArguments());
        $this->assertSame($exception, $failedEvent->getException());
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

        $this->assertSame('extension result', $result->getResult());
    }

    /* ───────── Visibility filtering (excluded/allowlist) ───────── */

    public function testExecuteThrowsToolNotFoundExceptionForExcludedTool(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');

        $registry->registerTool(
            name: 'bash',
            description: 'Bash',
            parametersJsonSchema: [],
            handler: $handler,
            promptLine: 'bash: Shell',
        );
        $registry->setExcludedToolNames(['bash']);

        // Excluded tool must remain invisible to active listings
        $this->assertSame([], $registry->activeToolNames());

        $toolbox = new RegistryBackedToolbox($registry);

        try {
            $toolbox->execute(new ToolCall('call-excluded', 'bash', []));
            $this->fail('Expected ToolNotFoundException.');
        } catch (ToolNotFoundException) {
            // Handler must NOT be invoked
            $this->assertSame(0, $handler->calls);
        }
    }

    public function testExecuteThrowsToolNotFoundExceptionForAllowlistFilteredTool(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');

        $registry->registerTool(
            name: 'bash',
            description: 'Bash',
            parametersJsonSchema: [],
            handler: $handler,
            promptLine: 'bash: Shell',
        );
        $registry->registerTool(
            name: 'read',
            description: 'Read',
            parametersJsonSchema: [],
            handler: $this->dummyHandler('ok'),
            promptLine: 'read: Read',
        );

        // Only 'read' is allowed
        $registry->setAllowedToolNames(['read']);

        $this->assertSame(['read'], $registry->activeToolNames());

        $toolbox = new RegistryBackedToolbox($registry);

        // 'bash' is registered but not in the allowlist
        try {
            $toolbox->execute(new ToolCall('call-allowlisted', 'bash', []));
            $this->fail('Expected ToolNotFoundException.');
        } catch (ToolNotFoundException) {
            // Handler must NOT be invoked
            $this->assertSame(0, $handler->calls);
        }
    }

    public function testExecuteStillWorksForAllowedToolInAllowlist(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->dummyHandler('allowlisted result');

        $registry->registerTool(
            name: 'read',
            description: 'Read',
            parametersJsonSchema: [],
            handler: $handler,
            promptLine: 'read: Read',
        );
        $registry->registerTool(
            name: 'bash',
            description: 'Bash',
            parametersJsonSchema: [],
            handler: $this->dummyHandler('should not be called'),
            promptLine: 'bash: Shell',
        );

        $registry->setAllowedToolNames(['read']);

        $toolbox = new RegistryBackedToolbox($registry);
        $result = $toolbox->execute(new ToolCall('call-allowed', 'read', []));

        $this->assertSame('allowlisted result', $result->getResult());
    }

    /* ───────── Rewrite phase ───────── */

    public function testRewriteHookMutatesArguments(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();
        $registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $handler, promptLine: 'bash');

        $rewriteProvider = $this->stubRewriteProvider('bash', [
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    $args = $context->arguments;
                    $args['command'] = 'LLM_MODE=true '.$args['command'];

                    return $args;
                }
            },
        ]);

        $toolbox = new RegistryBackedToolbox($registry, rewriteHookProvider: $rewriteProvider);
        $toolbox->execute(new ToolCall('call-rw-1', 'bash', ['command' => 'castor test']));

        $this->assertSame(['command' => 'LLM_MODE=true castor test'], $handler->lastArgs);
    }

    public function testRewriteHookNullReturnLeavesArgsUnchanged(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();
        $registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $handler, promptLine: 'bash');

        $rewriteProvider = $this->stubRewriteProvider('bash', [
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    return null; // no-op
                }
            },
        ]);

        $toolbox = new RegistryBackedToolbox($registry, rewriteHookProvider: $rewriteProvider);
        $toolbox->execute(new ToolCall('call-rw-2', 'bash', ['command' => 'castor test']));

        $this->assertSame(['command' => 'castor test'], $handler->lastArgs);
    }

    public function testRewriteHookEventSeesRewrittenArgs(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();
        $registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $handler, promptLine: 'bash');

        $rewriteProvider = $this->stubRewriteProvider('bash', [
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    $args = $context->arguments;
                    $args['command'] = 'LLM_MODE=true '.$args['command'];

                    return $args;
                }
            },
        ]);

        $dispatcher = new EventDispatcher();
        $requestedArgs = null;
        $dispatcher->addListener(ToolCallRequested::class, static function (ToolCallRequested $event) use (&$requestedArgs): void {
            $requestedArgs = $event->getToolCall()->getArguments();
        });

        $toolbox = new RegistryBackedToolbox($registry, $dispatcher, $rewriteProvider);
        $toolbox->execute(new ToolCall('call-rw-3', 'bash', ['command' => 'castor test']));

        // The event listener must see the rewritten arguments
        $this->assertSame(['command' => 'LLM_MODE=true castor test'], $requestedArgs);
    }

    public function testMultipleRewriteHooksComposeLeftToRight(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();
        $registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $handler, promptLine: 'bash');

        $rewriteProvider = $this->stubRewriteProvider('bash', [
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    $args = $context->arguments;
                    $args['prefix'] = ($args['prefix'] ?? '').'first|';

                    return $args;
                }
            },
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    $args = $context->arguments;
                    $args['prefix'] = ($args['prefix'] ?? '').'second';

                    return $args;
                }
            },
        ]);

        $toolbox = new RegistryBackedToolbox($registry, rewriteHookProvider: $rewriteProvider);
        $toolbox->execute(new ToolCall('call-rw-4', 'bash', ['command' => 'test']));

        // Both hooks composed: first adds 'first|', second sees it and adds 'second'
        $this->assertSame(
            ['command' => 'test', 'prefix' => 'first|second'],
            $handler->lastArgs,
        );
    }

    public function testWildcardRewriteHookAppliesToAllTools(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();
        $registry->registerTool(name: 'bash', description: 'Bash', parametersJsonSchema: [], handler: $handler, promptLine: 'bash');

        $wildcardHook = new readonly class implements ToolCallRewriteHookInterface {
            public function rewriteArguments(ToolCallContextDTO $context): ?array
            {
                $args = $context->arguments;
                $args['injected'] = true;

                return $args;
            }
        };

        $rewriteProvider = new readonly class($wildcardHook) implements ToolCallRewriteHookProviderInterface {
            public function __construct(
                private ToolCallRewriteHookInterface $hook,
            ) {
            }

            public function rewriteHooksForTool(string $toolName): array
            {
                return [$this->hook];
            }
        };

        $toolbox = new RegistryBackedToolbox($registry, rewriteHookProvider: $rewriteProvider);
        $toolbox->execute(new ToolCall('call-rw-5', 'bash', ['command' => 'test']));

        $this->assertSame(
            ['command' => 'test', 'injected' => true],
            $handler->lastArgs,
        );
    }

    public function testRewriteDoesNotRunForUnregisteredTool(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();
        $registry->registerTool(name: 'read', description: 'Read', parametersJsonSchema: [], handler: $handler, promptLine: 'read');

        $rewriteProvider = $this->stubRewriteProvider('bash', [
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    $args = $context->arguments;
                    $args['rewritten'] = true;

                    return $args;
                }
            },
        ]);

        $toolbox = new RegistryBackedToolbox($registry, rewriteHookProvider: $rewriteProvider);
        $toolbox->execute(new ToolCall('call-rw-6', 'read', ['path' => 'x']));

        // Rewrite was registered for 'bash', not 'read' — args unchanged
        $this->assertSame(['path' => 'x'], $handler->lastArgs);
    }

    public function testRewriteWithoutRewriteProvider(): void
    {
        // Ensure backward compat: null provider doesn't break execution
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();
        $registry->registerTool(name: 'tool', description: 'Tool', parametersJsonSchema: [], handler: $handler, promptLine: 'tool');

        $toolbox = new RegistryBackedToolbox($registry, rewriteHookProvider: null);
        $toolbox->execute(new ToolCall('call-rw-7', 'tool', ['arg' => 'val']));

        $this->assertSame(['arg' => 'val'], $handler->lastArgs);
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

    /**
     * Handler that records the last arguments it was called with.
     */
    private function capturingHandler(): ToolHandlerInterface
    {
        return new class implements ToolHandlerInterface {
            public ?array $lastArgs = null;

            public function __invoke(array $arguments): mixed
            {
                $this->lastArgs = $arguments;

                return 'ok';
            }
        };
    }

    /**
     * @param list<ToolCallRewriteHookInterface> $hooks
     */
    private function stubRewriteProvider(string $toolName, array $hooks): ToolCallRewriteHookProviderInterface
    {
        return new readonly class($toolName, $hooks) implements ToolCallRewriteHookProviderInterface {
            /** @param list<ToolCallRewriteHookInterface> $hooks */
            public function __construct(
                private string $toolName,
                private array $hooks,
            ) {
            }

            public function rewriteHooksForTool(string $toolName): array
            {
                return $this->toolName === $toolName ? $this->hooks : [];
            }
        };
    }
}
