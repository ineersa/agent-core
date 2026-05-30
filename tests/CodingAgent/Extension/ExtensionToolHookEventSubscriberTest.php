<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Extension\ExtensionToolHookEventSubscriber;
use Ineersa\CodingAgent\Extension\ExtensionToolRegistryBridge;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\Hatfield\ExtensionApi\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\ToolResultContextDTO;
use Ineersa\Hatfield\ExtensionApi\ToolResultDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\ToolResultHookInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class ExtensionToolHookEventSubscriberTest extends TestCase
{
    public function testRegisteredToolCallHookBlocksToolExecutorPathBeforeHandlerRuns(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool('guarded', 'Guarded tool', [], $handler, 'guarded');

        $hookRegistry = new ExtensionHookRegistry();
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            cwd: getcwd() ?: '/',
        );
        $bridge = new ExtensionToolRegistryBridge($registry, $hookRegistry, $appConfig);
        $seenContext = null;
        $bridge->registerToolCallHook(new class($seenContext) implements ToolCallHookInterface {
            public function __construct(
                private mixed &$seenContext,
            ) {
            }

            public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO
            {
                $this->seenContext = $context;

                return ToolCallDecisionDTO::block('dangerous_command', ['category' => 'safe_guard']);
            }
        });

        $contextAccessor = new StackToolExecutionContextAccessor();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, getcwd() ?: '/', $contextAccessor));

        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 2,
            resultStore: new ToolExecutionResultStore(),
            toolbox: new RegistryBackedToolbox($registry, $dispatcher),
            contextAccessor: $contextAccessor,
        );

        $result = $executor->execute(new ToolCall(
            toolCallId: 'call-guarded',
            toolName: 'guarded',
            arguments: ['cmd' => 'rm -rf var'],
            orderIndex: 7,
            runId: 'run-safe',
            context: ['turn_no' => 3],
        ));

        $this->assertSame(0, $handler->calls);
        $this->assertFalse($result->isError);
        $this->assertIsArray($result->details['raw_result'] ?? null);
        $this->assertTrue($result->details['raw_result']['denied'] ?? null);
        $this->assertSame('dangerous_command', $result->details['raw_result']['reason'] ?? null);
        $this->assertSame('safe_guard', $result->details['raw_result']['category'] ?? null);

        $this->assertInstanceOf(ToolCallContextDTO::class, $seenContext);
        $this->assertSame('call-guarded', $seenContext->toolCallId);
        $this->assertSame('guarded', $seenContext->toolName);
        $this->assertSame(['cmd' => 'rm -rf var'], $seenContext->arguments);
        $this->assertSame(7, $seenContext->orderIndex);
        $this->assertSame('run-safe', $seenContext->runId);
        $this->assertSame(3, $seenContext->turnNo);
        $this->assertNotNull($seenContext->cwd);
        $this->assertSame(30, $seenContext->metadata['timeout_seconds']);
    }

    public function testToolCallHooksRunInOrderAndFirstNonAllowWins(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('handler result');
        $registry->registerTool('ordered', 'Ordered tool', [], $handler, 'ordered');

        $hookRegistry = new ExtensionHookRegistry();
        $calls = [];
        $hookRegistry->addToolCallHook($this->toolCallHook(static function () use (&$calls): ToolCallDecisionDTO {
            $calls[] = 'first';

            return ToolCallDecisionDTO::allow();
        }));
        $hookRegistry->addToolCallHook($this->toolCallHook(static function () use (&$calls): ToolCallDecisionDTO {
            $calls[] = 'second';

            return ToolCallDecisionDTO::replaceResult(['from' => 'second']);
        }));
        $hookRegistry->addToolCallHook($this->toolCallHook(static function () use (&$calls): ToolCallDecisionDTO {
            $calls[] = 'third';

            return ToolCallDecisionDTO::block('should_not_run');
        }));

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, getcwd() ?: '/'));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-ordered', 'ordered', [])
        );

        $this->assertSame(['from' => 'second'], $result->getResult());
        $this->assertSame(0, $handler->calls);
        $this->assertSame(['first', 'second'], $calls);
    }

    public function testToolResultHooksRunInOrderWithLatestLocalResultState(): void
    {
        $registry = new ToolRegistry();
        $registry->registerTool('resultful', 'Resultful tool', [], $this->countingHandler(['initial' => true]), 'resultful');

        $hookRegistry = new ExtensionHookRegistry();
        $seen = [];
        $hookRegistry->addToolResultHook($this->toolResultHook(static function (ToolResultContextDTO $context) use (&$seen): ToolResultDecisionDTO {
            $seen[] = ['first', $context->content[0]['text'], $context->details];

            return ToolResultDecisionDTO::replace(
                content: [['type' => 'text', 'text' => 'changed locally']],
                details: ['stage' => 'first'],
            );
        }));
        $hookRegistry->addToolResultHook($this->toolResultHook(static function (ToolResultContextDTO $context) use (&$seen): ToolResultDecisionDTO {
            $seen[] = ['second', $context->content[0]['text'], $context->details];

            return ToolResultDecisionDTO::keep();
        }));

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, getcwd() ?: '/'));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-resultful', 'resultful', [])
        );

        $this->assertSame(['initial' => true], $result->getResult());
        $this->assertSame([
            ['first', '{"initial":true}', ['raw_result' => ['initial' => true]]],
            ['second', 'changed locally', ['stage' => 'first']],
        ], $seen);
    }

    public function testToolCallHookFailureReturnsStructuredResultAndSkipsHandler(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool('throws', 'Throws', [], $handler, 'throws');

        $hookRegistry = new ExtensionHookRegistry();
        $hookRegistry->addToolCallHook($this->toolCallHook(static function (): ToolCallDecisionDTO {
            throw new \RuntimeException('hook exploded');
        }));

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, getcwd() ?: '/'));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-throws', 'throws', [])
        );

        $this->assertSame(0, $handler->calls);
        $this->assertTrue($result->getResult()['denied'] ?? null);
        $this->assertSame('extension_tool_call_hook_failed', $result->getResult()['reason'] ?? null);
        $this->assertSame(\RuntimeException::class, $result->getResult()['error_type'] ?? null);
    }

    public function testEmptyHookRegistryPassesThroughToHandler(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('handler_ran');
        $registry->registerTool('unhooked', 'Unhooked tool', [], $handler, 'unhooked');

        $hookRegistry = new ExtensionHookRegistry();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, getcwd() ?: '/'));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-unhooked', 'unhooked', [])
        );

        $this->assertSame('handler_ran', $result->getResult());
        $this->assertSame(1, $handler->calls);
    }

    public function testEmptyHookRegistryWithContextAccessorStillPassesThrough(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('handler_ran');
        $registry->registerTool('unhooked', 'Unhooked tool', [], $handler, 'unhooked');

        $hookRegistry = new ExtensionHookRegistry();
        $contextAccessor = new StackToolExecutionContextAccessor();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, getcwd() ?: '/', $contextAccessor));

        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: 30,
            maxParallelism: 2,
            resultStore: new ToolExecutionResultStore(),
            toolbox: new RegistryBackedToolbox($registry, $dispatcher),
            contextAccessor: $contextAccessor,
        );

        $result = $executor->execute(new ToolCall(
            toolCallId: 'call-unhooked',
            toolName: 'unhooked',
            arguments: [],
            orderIndex: 0,
            runId: 'run-empty',
        ));

        $this->assertFalse($result->isError);
        $this->assertSame(1, $handler->calls);
    }

    private function toolCallHook(callable $callback): ToolCallHookInterface
    {
        return new class($callback) implements ToolCallHookInterface {
            public function __construct(
                private readonly mixed $callback,
            ) {
            }

            public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO
            {
                return ($this->callback)($context);
            }
        };
    }

    private function toolResultHook(callable $callback): ToolResultHookInterface
    {
        return new class($callback) implements ToolResultHookInterface {
            public function __construct(
                private readonly mixed $callback,
            ) {
            }

            public function onToolResult(ToolResultContextDTO $context): ToolResultDecisionDTO
            {
                return ($this->callback)($context);
            }
        };
    }

    private function countingHandler(mixed $result): object
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
