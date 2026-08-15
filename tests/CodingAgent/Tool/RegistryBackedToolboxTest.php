<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Agent\Artifact\AgentRetrieveArgumentsDTO;
use Ineersa\CodingAgent\Agent\Tool\SubagentToolDefinitionBuilder;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolCallArgumentsValidator;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallRewriteHookInterface;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\Event\ToolCallArgumentsResolved;
use Symfony\AI\Agent\Toolbox\Event\ToolCallFailed;
use Symfony\AI\Agent\Toolbox\Event\ToolCallRequested;
use Symfony\AI\Agent\Toolbox\Event\ToolCallSucceeded;
use Symfony\AI\Agent\Toolbox\Exception\InvalidToolCallArgumentsException;
use Symfony\AI\Agent\Toolbox\Exception\ToolExecutionException;
use Symfony\AI\Agent\Toolbox\Exception\ToolNotFoundException;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolCallArgumentResolver;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\PropertyInfo\Extractor\PhpDocExtractor;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\PropertyInfo\PropertyInfoExtractor;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\ArrayDenormalizer;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

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
        $toolbox = $this->createToolbox($registry);

        $this->assertInstanceOf(ToolboxInterface::class, $toolbox);
    }

    /* ───────── getTools() ───────── */

    public function testGetToolsReturnsEmptyForEmptyRegistry(): void
    {
        $registry = new ToolRegistry();
        $toolbox = $this->createToolbox($registry);

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

        $toolbox = $this->createToolbox($registry);
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

        $toolbox = $this->createToolbox($registry);
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

        $toolbox = $this->createToolbox($registry);
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

        $toolbox = $this->createToolbox($registry);
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

        $toolbox = $this->createToolbox($registry);
        $result = $toolbox->execute(new ToolCall('call-2', 'fg_tool', []));

        $this->assertSame('dynamic result', $result->getResult());
    }

    public function testExecuteForHandlerWithNoArguments(): void
    {
        $registry = new ToolRegistry();

        $handler = $this->dummyHandler('no-args result');
        $registry->registerTool(name: 'ping', description: 'Ping', parametersJsonSchema: [], handler: $handler, promptLine: 'ping: Ping');

        $toolbox = $this->createToolbox($registry);
        $result = $toolbox->execute(new ToolCall('call-3', 'ping', []));

        $this->assertSame('no-args result', $result->getResult());
    }

    public function testExecuteThrowsToolNotFoundException(): void
    {
        $registry = new ToolRegistry();
        $toolbox = $this->createToolbox($registry);

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
            $events[] = ['requested', $event->getToolCall()->getName(), $event->getDefinition()->getName()];
        });
        $dispatcher->addListener(ToolCallArgumentsResolved::class, static function (ToolCallArgumentsResolved $event) use (&$events, $handler): void {
            $events[] = ['arguments_resolved', $event->getTool() === $handler, $event->getArguments()];
        });
        $dispatcher->addListener(ToolCallSucceeded::class, static function (ToolCallSucceeded $event) use (&$events, $handler): void {
            $events[] = ['succeeded', $event->getTool() === $handler, $event->getArguments(), $event->getResult()->getResult()];
        });

        $toolbox = $this->createToolbox($registry, $dispatcher);
        $result = $toolbox->execute(new ToolCall('call-events', 'evented', ['query' => 'hello']));

        $this->assertSame('evented result', $result->getResult());
        $this->assertSame([
            ['requested', 'evented', 'evented'],
            // Native resolution nests flat provider args under the sole handler parameter name.
            ['arguments_resolved', true, ['arguments' => ['query' => 'hello']]],
            // Succeeded keeps the flat rewritten provider args (pre-task contract).
            ['succeeded', true, ['query' => 'hello'], 'evented result'],
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

        $toolbox = $this->createToolbox($registry, $dispatcher);
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

        $toolbox = $this->createToolbox($registry, $dispatcher);
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

        $toolbox = $this->createToolbox($registry, $dispatcher);

        try {
            $toolbox->execute(new ToolCall('call-failed', 'failing', ['path' => 'x']));
            $this->fail('Expected handler exception to be re-thrown unchanged.');
        } catch (\RuntimeException $caught) {
            // Pre-task contract: handler throwables propagate unchanged so ToolExecutor
            // classifies message/hint/retryable (FaultTolerantToolbox only converts
            // ToolExecutionExceptionInterface).
            $this->assertSame($exception, $caught);
        }

        $this->assertInstanceOf(ToolCallFailed::class, $failedEvent);
        $this->assertSame($handler, $failedEvent->getTool());
        $this->assertSame('failing', $failedEvent->getDefinition()->getName());
        // Lifecycle failed event keeps the flat provider args (pre-task contract).
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

        $toolbox = $this->createToolbox($registry);
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

        $toolbox = $this->createToolbox($registry);

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

        $toolbox = $this->createToolbox($registry);

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

        $toolbox = $this->createToolbox($registry);
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

        $toolbox = $this->createToolbox($registry, rewriteHookProvider: $rewriteProvider);
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

        $toolbox = $this->createToolbox($registry, rewriteHookProvider: $rewriteProvider);
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

        $toolbox = $this->createToolbox($registry, $dispatcher, $rewriteProvider);
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

        $toolbox = $this->createToolbox($registry, rewriteHookProvider: $rewriteProvider);
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

        $rewriteProvider = new ExtensionHookRegistry();
        $rewriteProvider->addToolCallRewriteHook('*', $wildcardHook);

        $toolbox = $this->createToolbox($registry, rewriteHookProvider: $rewriteProvider);
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

        $toolbox = $this->createToolbox($registry, rewriteHookProvider: $rewriteProvider);
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

        $toolbox = $this->createToolbox($registry, rewriteHookProvider: null);
        $toolbox->execute(new ToolCall('call-rw-7', 'tool', ['arg' => 'val']));

        $this->assertSame(['arg' => 'val'], $handler->lastArgs);
    }

    public function testSchemaValidationRejectsMissingRequiredAndDoesNotInvokeHandler(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool(
            name: 'write',
            description: 'Write',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string'],
                    'content' => ['type' => 'string'],
                ],
                'required' => ['path', 'content'],
                'additionalProperties' => false,
            ],
            handler: $handler,
            promptLine: 'write',
        );

        $toolbox = new FaultTolerantToolbox($this->createToolbox($registry));
        $result = $toolbox->execute(new ToolCall('call-missing', 'write', ['path' => 'a.txt']));

        $this->assertSame(0, $handler->calls);
        $this->assertIsString($result->getResult());
        $this->assertStringContainsString('Invalid arguments for tool "write"', (string) $result->getResult());
    }

    public function testSchemaValidationRejectsUnknownProperties(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool(
            name: 'view',
            description: 'View',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'path' => ['type' => 'string'],
                ],
                'required' => ['path'],
                'additionalProperties' => false,
            ],
            handler: $handler,
            promptLine: 'view',
        );

        $toolbox = new FaultTolerantToolbox($this->createToolbox($registry));
        $result = $toolbox->execute(new ToolCall('call-unknown', 'view', ['path' => 'a.png', 'extra' => true]));

        $this->assertSame(0, $handler->calls);
        $this->assertStringContainsString('Invalid arguments for tool "view"', (string) $result->getResult());
    }

    public function testSchemaValidationRejectsConstrainedInvalidInput(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool(
            name: 'bg',
            description: 'Bg',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'action' => ['type' => 'string', 'enum' => ['list', 'log', 'stop']],
                    'pid' => ['type' => 'integer', 'minimum' => 1],
                ],
                'required' => ['action'],
                'additionalProperties' => false,
            ],
            handler: $handler,
            promptLine: 'bg',
        );

        $toolbox = new FaultTolerantToolbox($this->createToolbox($registry));
        $result = $toolbox->execute(new ToolCall('call-enum', 'bg', ['action' => 'pause']));

        $this->assertSame(0, $handler->calls);
        $this->assertStringContainsString('Invalid arguments for tool "bg"', (string) $result->getResult());
    }

    public function testRewriteThenSchemaValidationUsesRewrittenArgsForPolicyAndValidation(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->capturingHandler();
        $registry->registerTool(
            name: 'bash',
            description: 'Bash',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string'],
                ],
                'required' => ['command'],
                'additionalProperties' => false,
            ],
            handler: $handler,
            promptLine: 'bash',
        );

        $rewriteProvider = $this->stubRewriteProvider('bash', [
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    return ['command' => 'rewritten-'.$context->arguments['command']];
                }
            },
        ]);

        $dispatcher = new EventDispatcher();
        $requested = null;
        $dispatcher->addListener(ToolCallRequested::class, static function (ToolCallRequested $event) use (&$requested): void {
            $requested = $event->getToolCall()->getArguments();
        });

        $toolbox = $this->createToolbox($registry, $dispatcher, $rewriteProvider);
        $toolbox->execute(new ToolCall('call-rw-valid', 'bash', ['command' => 'echo']));

        $this->assertSame(['command' => 'rewritten-echo'], $requested);
        $this->assertSame(['command' => 'rewritten-echo'], $handler->lastArgs);
    }

    public function testRewriteToInvalidArgsBecomesFaultTolerantResultWithoutHandler(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('nope');
        $registry->registerTool(
            name: 'bash',
            description: 'Bash',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'command' => ['type' => 'string'],
                ],
                'required' => ['command'],
                'additionalProperties' => false,
            ],
            handler: $handler,
            promptLine: 'bash',
        );

        $rewriteProvider = $this->stubRewriteProvider('bash', [
            new readonly class implements ToolCallRewriteHookInterface {
                public function rewriteArguments(ToolCallContextDTO $context): ?array
                {
                    return ['command' => 123];
                }
            },
        ]);

        $toolbox = new FaultTolerantToolbox($this->createToolbox($registry, rewriteHookProvider: $rewriteProvider));
        $result = $toolbox->execute(new ToolCall('call-rw-invalid', 'bash', ['command' => 'echo']));

        $this->assertSame(0, $handler->calls);
        $this->assertStringContainsString('Invalid arguments for tool "bash"', (string) $result->getResult());
    }

    public function testNativeDtoResolutionDispatchesResolvedObjectArguments(): void
    {
        $registry = new ToolRegistry();
        $handler = new class implements ToolHandlerInterface {
            public ?object $seen = null;

            public function __invoke(\Ineersa\CodingAgent\Tool\Arguments\ViewImageArgumentsDTO $arguments): string
            {
                $this->seen = $arguments;

                return 'ok:'.$arguments->path;
            }
        };
        $registry->registerTool(
            name: 'view_image',
            description: 'View',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => ['path' => ['type' => 'string']],
                'required' => ['path'],
                'additionalProperties' => false,
            ],
            handler: $handler,
            promptLine: 'view_image',
        );

        $dispatcher = new EventDispatcher();
        $resolved = null;
        $dispatcher->addListener(ToolCallArgumentsResolved::class, static function (ToolCallArgumentsResolved $event) use (&$resolved): void {
            $resolved = $event->getArguments();
        });

        $toolbox = $this->createToolbox($registry, $dispatcher);
        $result = $toolbox->execute(new ToolCall('call-dto', 'view_image', ['path' => 'img.png']));

        $this->assertSame('ok:img.png', $result->getResult());
        $this->assertIsArray($resolved);
        $this->assertArrayHasKey('arguments', $resolved);
        $this->assertInstanceOf(\Ineersa\CodingAgent\Tool\Arguments\ViewImageArgumentsDTO::class, $resolved['arguments']);
        $this->assertSame('img.png', $resolved['arguments']->path);
        $this->assertInstanceOf(\Ineersa\CodingAgent\Tool\Arguments\ViewImageArgumentsDTO::class, $handler->seen);
    }

    /* ───────── Reviewer regressions: serializer, exception survival, flat events ───────── */

    public function testSnakeCaseProviderKeysDenormalizeToCamelCaseDtoProperties(): void
    {
        $registry = new ToolRegistry();
        $handler = new class implements ToolHandlerInterface {
            public ?AgentRetrieveArgumentsDTO $seen = null;

            public function __invoke(AgentRetrieveArgumentsDTO $arguments): string
            {
                $this->seen = $arguments;

                return 'ok:'.$arguments->artifactId;
            }
        };
        $registry->registerTool(
            name: 'agent_retrieve',
            description: 'Retrieve',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => [
                    'artifact_id' => ['type' => 'string', 'minLength' => 1],
                    'agent_run_id' => ['type' => 'string', 'minLength' => 1],
                    'mode' => ['type' => 'string', 'enum' => ['handoff', 'metadata', 'events', 'history', 'debug']],
                    'limit' => ['type' => 'integer', 'minimum' => 1],
                ],
                'required' => [],
                'additionalProperties' => false,
            ],
            handler: $handler,
            promptLine: 'agent_retrieve',
        );

        // Real resolver path with the app serializer stack (camel_case_to_snake_case
        // name converter) — no hand-written key mapping anywhere.
        $toolbox = $this->createToolbox($registry, resolver: $this->createNameConverterResolver());
        $result = $toolbox->execute(new ToolCall('call-snake', 'agent_retrieve', ['artifact_id' => 'agent_abc', 'limit' => 5]));

        $this->assertSame('ok:agent_abc', $result->getResult());
        $this->assertInstanceOf(AgentRetrieveArgumentsDTO::class, $handler->seen);
        $this->assertSame('agent_abc', $handler->seen->artifactId);
        $this->assertNull($handler->seen->agentRunId);
        $this->assertSame(5, $handler->seen->limit);
    }

    public function testHandlerToolCallExceptionPropagatesUnchangedThroughFaultTolerantToolbox(): void
    {
        $registry = new ToolRegistry();
        $exception = new ToolCallException('Something went wrong', retryable: true, hint: 'Try again with different input');
        $handler = new class($exception) implements ToolHandlerInterface {
            public function __construct(
                private readonly ToolCallException $exception,
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

        $toolbox = new FaultTolerantToolbox($this->createToolbox($registry, $dispatcher));

        try {
            $toolbox->execute(new ToolCall('call-failing', 'failing', ['path' => 'x']));
            $this->fail('Expected the ToolCallException to propagate unchanged.');
        } catch (ToolCallException $caught) {
            // FaultTolerantToolbox only converts ToolExecutionExceptionInterface, so
            // ToolCallException must reach ToolExecutor with full fidelity.
            $this->assertSame($exception, $caught);
            $this->assertSame('Something went wrong', $caught->getMessage());
            $this->assertTrue($caught->retryable());
            $this->assertSame('Try again with different input', $caught->hint());
        }

        $this->assertSame($exception, $failedEvent?->getException());
        $this->assertSame(['path' => 'x'], $failedEvent?->getArguments());
    }

    public function testSchemaFailureDispatchesFailedEventWithFlatInvalidArguments(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool(
            name: 'write',
            description: 'Write',
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => ['path' => ['type' => 'string'], 'content' => ['type' => 'string']],
                'required' => ['path', 'content'],
                'additionalProperties' => false,
            ],
            handler: $handler,
            promptLine: 'write',
        );

        $dispatcher = new EventDispatcher();
        $failedEvent = null;
        $dispatcher->addListener(ToolCallFailed::class, static function (ToolCallFailed $event) use (&$failedEvent): void {
            $failedEvent = $event;
        });

        $invalidArguments = ['path' => 'a.txt'];
        $toolbox = new FaultTolerantToolbox($this->createToolbox($registry, $dispatcher));
        $result = $toolbox->execute(new ToolCall('call-schema-fail', 'write', $invalidArguments));

        $this->assertSame(0, $handler->calls);
        $this->assertInstanceOf(ToolCallFailed::class, $failedEvent);
        // Schema-failure event carries the flat invalid final arguments.
        $this->assertSame($invalidArguments, $failedEvent->getArguments());
        $this->assertInstanceOf(InvalidToolCallArgumentsException::class, $failedEvent->getException());
        $this->assertIsString($result->getResult());
        $this->assertStringContainsString('Invalid arguments for tool "write"', (string) $result->getResult());
    }

    public function testResolverDenormalizerFailureWrappedAsToolExecutionException(): void
    {
        $registry = new ToolRegistry();
        $registry->registerTool(
            name: 'fragile',
            description: 'Fragile',
            // Provider schema says string; the handler parameter demands int, so the
            // value passes the schema but the resolver's denormalizer rejects it
            // (NotNormalizableValueException) — the resolver-failure path.
            parametersJsonSchema: [
                'type' => 'object',
                'properties' => ['count' => ['type' => 'string']],
                'additionalProperties' => false,
            ],
            handler: new class implements ToolHandlerInterface {
                public function __invoke(int $count): string
                {
                    return 'count:'.$count;
                }
            },
            promptLine: 'fragile',
        );

        // Resolver/denormalizer failure before handler invoke → wrapped as
        // ToolExecutionException → deterministic model-visible fault.
        $toolbox = new FaultTolerantToolbox($this->createToolbox($registry));
        $result = $toolbox->execute(new ToolCall('call-fragile', 'fragile', ['count' => 'abc']));

        $this->assertSame('An error occurred while executing tool "fragile".', (string) $result->getResult());
    }

    public function testOldSubagentBackgroundKeyRejectedByCanonicalSchema(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $definition = SubagentToolDefinitionBuilder::build(new AgentsConfig(), $handler);
        $registry->registerTool(
            name: $definition->name,
            description: $definition->description,
            parametersJsonSchema: $definition->parametersJsonSchema,
            handler: $definition->handler,
            promptLine: $definition->promptLine,
        );

        // 'background' was rejected by the pre-task factory; the canonical schema
        // (additionalProperties: false) now produces the deterministic fault.
        $toolbox = new FaultTolerantToolbox($this->createToolbox($registry));
        $result = $toolbox->execute(new ToolCall('call-old-key', 'subagent', ['agent' => 'scout', 'task' => 'x', 'background' => true]));

        $this->assertSame(0, $handler->calls);
        $message = (string) $result->getResult();
        $this->assertStringContainsString('Invalid arguments for tool "subagent"', $message);
        // Opis reports additionalProperties violations on the root pointer:
        // "/: Additional object properties are not allowed: background."
        $this->assertStringContainsString('Additional object properties are not allowed: background', $message);
    }

    /* ───────── Private helpers ───────── */

    private function createToolbox(
        ToolRegistry $registry,
        ?EventDispatcher $dispatcher = null,
        ?ExtensionHookRegistry $rewriteHookProvider = null,
        ?ToolCallArgumentResolver $resolver = null,
    ): RegistryBackedToolbox {
        return new RegistryBackedToolbox(
            registry: $registry,
            argumentResolver: $resolver ?? new ToolCallArgumentResolver(),
            argumentsValidator: new ToolCallArgumentsValidator(),
            eventDispatcher: $dispatcher,
            rewriteHookProvider: $rewriteHookProvider,
        );
    }

    /**
     * Real resolver with the app serializer stack: camel_case_to_snake_case
     * name converter + property type extractors (same as the @serializer service).
     */
    private function createNameConverterResolver(): ToolCallArgumentResolver
    {
        $propertyTypeExtractor = new PropertyInfoExtractor([], [new PhpDocExtractor(), new ReflectionExtractor()]);

        return new ToolCallArgumentResolver(new Serializer([
            new DateTimeNormalizer(),
            new BackedEnumNormalizer(),
            new ObjectNormalizer(
                nameConverter: new CamelCaseToSnakeCaseNameConverter(),
                propertyTypeExtractor: $propertyTypeExtractor,
            ),
            new ArrayDenormalizer(),
        ]));
    }

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
    private function stubRewriteProvider(string $toolName, array $hooks): ExtensionHookRegistry
    {
        $registry = new ExtensionHookRegistry();
        foreach ($hooks as $hook) {
            $registry->addToolCallRewriteHook($toolName, $hook);
        }

        return $registry;
    }
}
