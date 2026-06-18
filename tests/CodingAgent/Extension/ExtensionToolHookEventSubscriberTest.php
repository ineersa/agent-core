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
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $this->createStub(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class), getcwd() ?: '/', $contextAccessor));

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
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $this->createStub(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class), getcwd() ?: '/'));

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
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $this->createStub(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class), getcwd() ?: '/'));

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
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $this->createStub(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class), getcwd() ?: '/'));

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
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $this->createStub(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class), getcwd() ?: '/'));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-unhooked', 'unhooked', [])
        );

        $this->assertSame('handler_ran', $result->getResult());
        $this->assertSame(1, $handler->calls);
    }

    public function testRequireApprovalWithPreAnsweredQuestionAllowsToolExecution(): void
    {
        // Test that a RequireApproval decision, when a pre-answered ToolQuestion
        // already exists (crash recovery / redelivery), processes the existing
        // answer and lets the tool handler run for Allow once.
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('handler_ran');
        $registry->registerTool('approval-tool', 'Tool needing approval', [], $handler, 'approval-tool');

        $hookRegistry = new ExtensionHookRegistry();
        $hookRegistry->addToolCallHook($this->toolCallHook(static function (ToolCallContextDTO $context): ToolCallDecisionDTO {
            return ToolCallDecisionDTO::requireApproval(
                prompt: 'Allow?',
                questionId: 'sg_qid_test',
                schema: ['type' => 'string', 'enum' => ['Allow once', 'Always allow', 'Deny']],
                details: [
                    'category' => 'destructive',
                    'tool_name' => 'bash',
                ],
            );
        }));

        // Create a pre-answered ToolQuestion (simulating crash recovery where
        // the answer was already written by a previous controller instance).
        $existingQuestion = new \Ineersa\CodingAgent\Entity\ToolQuestion();
        $existingQuestion->requestId = 'sg_run-test_call-approve-1';
        $existingQuestion->runId = 'run-test';
        $existingQuestion->toolCallId = 'call-approve-1';
        $existingQuestion->toolName = 'approval-tool';
        $existingQuestion->prompt = 'Allow?';
        $existingQuestion->kind = 'safeguard_approval';
        $existingQuestion->answerText = 'Allow once';
        $existingQuestion->status = \Ineersa\CodingAgent\Entity\ToolQuestionStatusEnum::Answered;

        $store = $this->createMock(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class);
        $store->expects($this->once())
            ->method('findByRequestId')
            ->willReturn($existingQuestion);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $store, getcwd() ?: '/'));

        // The tool box receives a ToolCall WITH a runId (via the ToolCall's
        // 'context' metadata, which is picked up by StackToolExecutionContextAccessor
        // in production but is not available in this unit test). Instead, the
        // subscriber uses toolCall->getId() to build the requestId.
        // The stub store returns the pre-answered question -> processApprovalAnswer
        // runs with Allow once -> the handler executes.
        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-approve-1', 'approval-tool', [])
        );

        // The handler should have run because the pre-answered question says
        // "Allow once", causing the subscriber to fall through to execution.
        $this->assertSame('handler_ran', $result->getResult());
        $this->assertSame(1, $handler->calls);
    }

    public function testRequireApprovalWithPreAnsweredDenyBlocksToolExecution(): void
    {
        // Test that a pre-answered ToolQuestion with 'Deny' prevents execution.
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool('deny-tool', 'Tool', [], $handler, 'deny-tool');

        $hookRegistry = new ExtensionHookRegistry();
        $hookRegistry->addToolCallHook($this->toolCallHook(static function (ToolCallContextDTO $context): ToolCallDecisionDTO {
            return ToolCallDecisionDTO::requireApproval(
                prompt: 'Allow?',
                questionId: 'sg_qid_deny',
            );
        }));

        $existingQuestion = new \Ineersa\CodingAgent\Entity\ToolQuestion();
        $existingQuestion->requestId = 'sg_run-denytest_call-deny';
        $existingQuestion->runId = 'run-denytest';
        $existingQuestion->toolCallId = 'call-deny';
        $existingQuestion->toolName = 'deny-tool';
        $existingQuestion->prompt = 'Allow?';
        $existingQuestion->kind = 'safeguard_approval';
        $existingQuestion->answerText = 'Deny';
        $existingQuestion->status = \Ineersa\CodingAgent\Entity\ToolQuestionStatusEnum::Answered;

        $store = $this->createMock(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class);
        $store->expects($this->once())
            ->method('findByRequestId')
            ->willReturn($existingQuestion);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $store, getcwd() ?: '/'));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-deny', 'deny-tool', [])
        );

        // The handler should NOT have run because Deny sets a denied result.
        $this->assertSame(0, $handler->calls);
        $this->assertIsArray($result->getResult());
        $this->assertSame(true, $result->getResult()['denied'] ?? null);
        $this->assertSame('safeguard_denied', $result->getResult()['reason'] ?? null);
    }

    public function testRequireApprovalWithMultipleHooksFirstRequireApprovalWinsAndProcessesPreAnswered(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('handler_ran');
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

        $existingQuestion = new \Ineersa\CodingAgent\Entity\ToolQuestion();
        $existingQuestion->requestId = 'sg_run-multi_call-multi';
        $existingQuestion->runId = 'run-multi';
        $existingQuestion->toolCallId = 'call-multi';
        $existingQuestion->toolName = 'multi-approval';
        $existingQuestion->prompt = 'Second hook says approve?';
        $existingQuestion->kind = 'safeguard_approval';
        $existingQuestion->answerText = 'Allow once';
        $existingQuestion->status = \Ineersa\CodingAgent\Entity\ToolQuestionStatusEnum::Answered;

        $store = $this->createMock(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class);
        $store->expects($this->once())
            ->method('findByRequestId')
            ->willReturn($existingQuestion);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $store, getcwd() ?: '/'));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-multi', 'multi-approval', [])
        );

        // The handler should have run because the pre-answered question allows
        // execution (Allow once).
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
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $this->createStub(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class), getcwd() ?: '/', $contextAccessor));

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
