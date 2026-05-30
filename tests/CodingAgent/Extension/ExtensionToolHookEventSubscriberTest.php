<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ExtensionsConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\ToolsConfig;
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
        $bridge = new ExtensionToolRegistryBridge($registry, $hookRegistry, self::appConfig());
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
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $contextAccessor));

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
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry));

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
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry));

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
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry));

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
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-unhooked', 'unhooked', [])
        );

        $this->assertSame('handler_ran', $result->getResult());
        $this->assertSame(1, $handler->calls);
    }

    public function testRequireApprovalCreatesInterruptPayloadWithApprovalContext(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool('approval-needing', 'Tool needing approval', [], $handler, 'approval-needing');

        $hookRegistry = new ExtensionHookRegistry();
        $hookRegistry->addToolCallHook($this->toolCallHook(static function (ToolCallContextDTO $context): ToolCallDecisionDTO {
            return ToolCallDecisionDTO::requireApproval(
                prompt: 'Allow destructive command: rm -rf /tmp/build?',
                questionId: 'sg_qid_test',
                schema: ['type' => 'string', 'enum' => ['Allow once', 'Always allow', 'Deny']],
                details: [
                    'category' => 'destructive',
                    'command' => 'rm -rf /tmp/build',
                    'tool_name' => 'bash',
                ],
            );
        }));

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-approve', 'approval-needing', [])
        );

        $this->assertSame(0, $handler->calls);
        $this->assertIsArray($result->getResult());
        $this->assertSame('interrupt', $result->getResult()['kind']);
        $this->assertSame('sg_qid_test', $result->getResult()['question_id']);
        $this->assertSame('Allow destructive command: rm -rf /tmp/build?', $result->getResult()['prompt']);
        $this->assertSame(['type' => 'string', 'enum' => ['Allow once', 'Always allow', 'Deny']], $result->getResult()['schema']);
        $this->assertSame('approval-needing', $result->getResult()['tool_name']);
        $this->assertSame('call-approve', $result->getResult()['tool_call_id']);

        // approval_context preserves all extension-specific metadata
        $this->assertIsArray($result->getResult()['approval_context']);
        $this->assertSame('destructive', $result->getResult()['approval_context']['category']);
        $this->assertSame('rm -rf /tmp/build', $result->getResult()['approval_context']['command']);
        $this->assertSame('bash', $result->getResult()['approval_context']['tool_name']);
        // prompt and schema from requireApproval are in details, visible in approval_context
        $this->assertSame('Allow destructive command: rm -rf /tmp/build?', $result->getResult()['approval_context']['prompt']);
    }

    public function testRequireApprovalGeneratesQuestionIdWhenNoneProvided(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool('approval-auto', 'Tool', [], $handler, 'approval-auto');

        $hookRegistry = new ExtensionHookRegistry();
        $hookRegistry->addToolCallHook($this->toolCallHook(static function (): ToolCallDecisionDTO {
            return ToolCallDecisionDTO::requireApproval(prompt: 'Allow?');
        }));

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-auto', 'approval-auto', [])
        );

        $this->assertSame(0, $handler->calls);
        $this->assertIsArray($result->getResult());
        $this->assertSame('interrupt', $result->getResult()['kind']);
        // When no question_id is provided, the subscriber generates a sha256 hash
        $questionId = $result->getResult()['question_id'];
        $this->assertIsString($questionId);
        $this->assertSame(64, \strlen($questionId)); // sha256 hex length
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $questionId);
        $this->assertSame('Allow?', $result->getResult()['prompt']);
        $this->assertSame(['type' => 'string'], $result->getResult()['schema']);
    }

    public function testRequireApprovalWithMultipleHooksFirstNonAllowWins(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool('multi-approval', 'Multi', [], $handler, 'multi-approval');

        $hookRegistry = new ExtensionHookRegistry();
        $hookRegistry->addToolCallHook($this->toolCallHook(static function (): ToolCallDecisionDTO {
            return ToolCallDecisionDTO::allow();
        }));
        $hookRegistry->addToolCallHook($this->toolCallHook(static function (): ToolCallDecisionDTO {
            return ToolCallDecisionDTO::requireApproval(prompt: 'Second hook says approve?');
        }));
        $hookRegistry->addToolCallHook($this->toolCallHook(static function (): ToolCallDecisionDTO {
            return ToolCallDecisionDTO::block('should not reach');
        }));

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-multi', 'multi-approval', [])
        );

        $this->assertSame(0, $handler->calls);
        $this->assertSame('interrupt', $result->getResult()['kind']);
        $this->assertSame('Second hook says approve?', $result->getResult()['prompt']);
    }

    public function testEmptyHookRegistryWithContextAccessorStillPassesThrough(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('handler_ran');
        $registry->registerTool('unhooked', 'Unhooked tool', [], $handler, 'unhooked');

        $hookRegistry = new ExtensionHookRegistry();
        $contextAccessor = new StackToolExecutionContextAccessor();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $contextAccessor));

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

    private static function appConfig(): AppConfig
    {
        return new AppConfig(
            tui: new TuiConfig(theme: 'test'),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            extensions: new ExtensionsConfig(),
            tools: new ToolsConfig(),
            ai: null,
            raw: [],
            catalog: null,
            cwd: '',
        );
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
