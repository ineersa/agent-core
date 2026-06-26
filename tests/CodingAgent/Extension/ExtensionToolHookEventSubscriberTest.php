<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
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
use Ineersa\CodingAgent\Extension\NoninteractiveChildRunProbe;
use Ineersa\CodingAgent\Extension\ExtensionToolRegistryBridge;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\Hatfield\ExtensionApi\Command\CommandDefinitionDTO;
use Ineersa\Hatfield\ExtensionApi\Command\CommandRegistryInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecInterface;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\Hatfield\ExtensionApi\Command\ExtensionCommandHandlerInterface;
use Ineersa\Hatfield\ExtensionApi\Approval\ApprovalAnswerContextDTO;
use Ineersa\Hatfield\ExtensionApi\Approval\ApprovalAnswerHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCallHookInterface;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultContextDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultDecisionDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolResultHookInterface;
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
        $bridge = new ExtensionToolRegistryBridge(
            $registry,
            $hookRegistry,
            $appConfig,
            $this->stubExecBridge(),
            $this->stubCommandRegistry(),
        );
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
        $handler = $this->countingHandler('handler_run');
        $registry->registerTool('chain-tool', 'Chained tool', [], $handler, 'chain-tool');

        $hookRegistry = new ExtensionHookRegistry();
        $hookRegistry->addToolCallHook($this->toolCallHook(static fn (): ToolCallDecisionDTO => ToolCallDecisionDTO::allow()));
        $hookRegistry->addToolCallHook($this->toolCallHook(static fn (): ToolCallDecisionDTO => ToolCallDecisionDTO::block('first_blocked', ['from' => 'first'])));
        $hookRegistry->addToolCallHook($this->toolCallHook(static fn (): ToolCallDecisionDTO => ToolCallDecisionDTO::block('should_not_reach')));

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $this->createStub(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class), getcwd() ?: '/'));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-chain', 'chain-tool', []),
        );

        $this->assertSame(0, $handler->calls);
        $this->assertTrue($result->getResult()['denied'] ?? null);
        $this->assertSame('first_blocked', $result->getResult()['reason'] ?? null);
        $this->assertSame('first', $result->getResult()['from'] ?? null);
    }

    public function testRequireApprovalWithPreAnsweredQuestionAllowsToolExecution(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('handler_ran');
        $registry->registerTool('approval-tool', 'Tool needing approval', [], $handler, 'approval-tool');

        $hookRegistry = new ExtensionHookRegistry();
        $hook = $this->approvalAwareHook(static function (ToolCallContextDTO $context): ToolCallDecisionDTO {
            return ToolCallDecisionDTO::requireApproval(
                prompt: 'Allow?',
                questionId: 'qid_test',
                schema: ['type' => 'string', 'enum' => ['Allow once', 'Always allow', 'Deny']],
                details: ['category' => 'destructive', 'tool_name' => 'bash'],
            );
        }, static function (ApprovalAnswerContextDTO $context): ToolCallDecisionDTO {
            return 'Allow once' === $context->answer || 'Always allow' === $context->answer
                ? ToolCallDecisionDTO::allow()
                : ToolCallDecisionDTO::block('denied_by_test');
        });
        $hookRegistry->addToolCallHook($hook);

        // The requestId is generated from crc32b of the hook class + context runId + toolCallId.
        // When no context accessor provides a runId, the runId component is empty → ``.
        $hookId = \hash('crc32b', $hook::class);
        $expectedRequestId = \sprintf('%s__call-approve-1', $hookId);

        $existingQuestion = new \Ineersa\CodingAgent\Entity\ToolQuestion();
        $existingQuestion->requestId = $expectedRequestId;
        $existingQuestion->runId = '';
        $existingQuestion->toolCallId = 'call-approve-1';
        $existingQuestion->toolName = 'approval-tool';
        $existingQuestion->prompt = 'Allow?';
        $existingQuestion->kind = 'approval';
        $existingQuestion->answerText = 'Allow once';
        $existingQuestion->status = \Ineersa\CodingAgent\Entity\ToolQuestionStatusEnum::Answered;

        $store = $this->createMock(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class);
        $store->expects($this->once())
            ->method('findByRequestId')
            ->with($expectedRequestId)
            ->willReturn($existingQuestion);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $store, getcwd() ?: '/'));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-approve-1', 'approval-tool', [])
        );

        // The handler must have run because resolveApprovalAnswer returns allow() for "Allow once".
        $this->assertSame('handler_ran', $result->getResult());
        $this->assertSame(1, $handler->calls);
    }

    public function testRequireApprovalWithPreAnsweredDenyBlocksToolExecution(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool('deny-tool', 'Tool', [], $handler, 'deny-tool');

        $hookRegistry = new ExtensionHookRegistry();
        $hook = $this->approvalAwareHook(static function (ToolCallContextDTO $context): ToolCallDecisionDTO {
            return ToolCallDecisionDTO::requireApproval(
                prompt: 'Allow?',
                questionId: 'qid_deny',
            );
        }, static function (ApprovalAnswerContextDTO $context): ToolCallDecisionDTO {
            return 'Allow once' === $context->answer || 'Always allow' === $context->answer
                ? ToolCallDecisionDTO::allow()
                : ToolCallDecisionDTO::block('denied_by_test', ['message' => 'The human denied the operation.']);
        });
        $hookRegistry->addToolCallHook($hook);

        $hookId = \hash('crc32b', $hook::class);
        $expectedRequestId = \sprintf('%s__call-deny', $hookId);

        $existingQuestion = new \Ineersa\CodingAgent\Entity\ToolQuestion();
        $existingQuestion->requestId = $expectedRequestId;
        $existingQuestion->runId = '';
        $existingQuestion->toolCallId = 'call-deny';
        $existingQuestion->toolName = 'deny-tool';
        $existingQuestion->prompt = 'Allow?';
        $existingQuestion->kind = 'approval';
        $existingQuestion->answerText = 'Deny';
        $existingQuestion->status = \Ineersa\CodingAgent\Entity\ToolQuestionStatusEnum::Answered;

        $store = $this->createMock(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class);
        $store->expects($this->once())
            ->method('findByRequestId')
            ->with($expectedRequestId)
            ->willReturn($existingQuestion);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $store, getcwd() ?: '/'));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-deny', 'deny-tool', [])
        );

        // Handler must NOT have run because resolveApprovalAnswer returns block() for "Deny".
        $this->assertSame(0, $handler->calls);
        $this->assertIsArray($result->getResult());
        $this->assertSame(true, $result->getResult()['denied'] ?? null);
        $this->assertSame('denied_by_test', $result->getResult()['reason'] ?? null);
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
        $hook = $this->approvalAwareHook(static function (): ToolCallDecisionDTO {
            return ToolCallDecisionDTO::requireApproval(prompt: 'Second hook says approve?');
        }, static function (ApprovalAnswerContextDTO $context): ToolCallDecisionDTO {
            return ToolCallDecisionDTO::allow();
        });
        $hookRegistry->addToolCallHook($hook);
        $hookRegistry->addToolCallHook($this->toolCallHook(static function (): ToolCallDecisionDTO {
            return ToolCallDecisionDTO::block('should not reach');
        }));

        $hookId = \hash('crc32b', $hook::class);
        $expectedRequestId = \sprintf('%s__call-multi', $hookId);

        $existingQuestion = new \Ineersa\CodingAgent\Entity\ToolQuestion();
        $existingQuestion->requestId = $expectedRequestId;
        $existingQuestion->runId = '';
        $existingQuestion->toolCallId = 'call-multi';
        $existingQuestion->toolName = 'multi-approval';
        $existingQuestion->prompt = 'Second hook says approve?';
        $existingQuestion->kind = 'approval';
        $existingQuestion->answerText = 'Allow once';
        $existingQuestion->status = \Ineersa\CodingAgent\Entity\ToolQuestionStatusEnum::Answered;

        $store = $this->createMock(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class);
        $store->expects($this->once())
            ->method('findByRequestId')
            ->with($expectedRequestId)
            ->willReturn($existingQuestion);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $store, getcwd() ?: '/'));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-multi', 'multi-approval', [])
        );

        // Handler must have run — resolveApprovalAnswer returns allow().
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

    /**
     * OCP proof test: a dummy approval-granting extension with its OWN vocabulary,
     * schema, and outcome mapping drives the SAME generic subscriber/handler/TUI path
     * with ZERO edits to infra.
     *
     * This is the single most important test of the architecture refactor.
     * It proves the Open-Closed Principle is restored: adding a new approval
     * extension requires only implementing the ExtensionApi contracts.
     */
    public function testOcpProofSecondApprovalExtensionWorksGenerically(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('dummy_executed');
        $registry->registerTool('dummy-tool', 'Dummy tool', [], $handler, 'dummy-tool');

        $hookRegistry = new ExtensionHookRegistry();
        // Dummy extension with its own vocabulary: 'Proceed' / 'Abort' (not SafeGuard's).
        $dummyHook = $this->approvalAwareHook(
            onToolCall: static function (ToolCallContextDTO $context): ToolCallDecisionDTO {
                return ToolCallDecisionDTO::requireApproval(
                    prompt: 'Proceed with dummy operation?',
                    questionId: 'dummy_qid',
                    schema: ['type' => 'string', 'enum' => ['Proceed', 'Abort']],
                    details: ['source' => 'dummy_extension'],
                );
            },
            resolveApprovalAnswer: static function (ApprovalAnswerContextDTO $context): ToolCallDecisionDTO {
                return 'Proceed' === $context->answer
                    ? ToolCallDecisionDTO::allow()
                    : ToolCallDecisionDTO::block('dummy_denied', [
                        'message' => 'Tool "%s" was denied by dummy extension: the human aborted.',
                    ]);
            },
        );
        $hookRegistry->addToolCallHook($dummyHook);

        $hookId = \hash('crc32b', $dummyHook::class);
        $expectedRequestId = \sprintf('%s__call-dummy-1', $hookId);

        // Pre-answer the question with the dummy extension's own vocabulary.
        $existingQuestion = new \Ineersa\CodingAgent\Entity\ToolQuestion();
        $existingQuestion->requestId = $expectedRequestId;
        $existingQuestion->runId = '';
        $existingQuestion->toolCallId = 'call-dummy-1';
        $existingQuestion->toolName = 'dummy-tool';
        $existingQuestion->prompt = 'Proceed with dummy operation?';
        $existingQuestion->kind = 'approval';
        $existingQuestion->answerText = 'Proceed';
        $existingQuestion->status = \Ineersa\CodingAgent\Entity\ToolQuestionStatusEnum::Answered;

        $store = $this->createMock(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class);
        $store->expects($this->once())
            ->method('findByRequestId')
            ->with($expectedRequestId)
            ->willReturn($existingQuestion);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $store, getcwd() ?: '/'));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-dummy-1', 'dummy-tool', [])
        );

        // The handler must have run with the dummy extension's vocabulary ('Proceed' → allow).
        $this->assertSame('dummy_executed', $result->getResult());
        $this->assertSame(1, $handler->calls);

        // Test the deny path with the dummy extension's vocabulary.
        $handler2 = $this->countingHandler('should_not_run');
        $registry2 = new ToolRegistry();
        $registry2->registerTool('dummy-tool2', 'Dummy tool 2', [], $handler2, 'dummy-tool2');

        $hookRegistry2 = new ExtensionHookRegistry();
        $dummyHook2 = $this->approvalAwareHook(
            onToolCall: static function (ToolCallContextDTO $context): ToolCallDecisionDTO {
                return ToolCallDecisionDTO::requireApproval(
                    prompt: 'Proceed?',
                    questionId: 'dummy_qid2',
                    schema: ['type' => 'string', 'enum' => ['Proceed', 'Abort']],
                    details: ['source' => 'dummy_extension'],
                );
            },
            resolveApprovalAnswer: static function (ApprovalAnswerContextDTO $context): ToolCallDecisionDTO {
                return 'Proceed' === $context->answer
                    ? ToolCallDecisionDTO::allow()
                    : ToolCallDecisionDTO::block('dummy_denied', ['message' => 'Aborted by user.']);
            },
        );
        $hookRegistry2->addToolCallHook($dummyHook2);

        $hookId2 = \hash('crc32b', $dummyHook2::class);
        $expectedRequestId2 = \sprintf('%s__call-dummy-2', $hookId2);

        $existingQuestion2 = new \Ineersa\CodingAgent\Entity\ToolQuestion();
        $existingQuestion2->requestId = $expectedRequestId2;
        $existingQuestion2->runId = '';
        $existingQuestion2->toolCallId = 'call-dummy-2';
        $existingQuestion2->toolName = 'dummy-tool2';
        $existingQuestion2->prompt = 'Proceed?';
        $existingQuestion2->kind = 'approval';
        $existingQuestion2->answerText = 'Abort';
        $existingQuestion2->status = \Ineersa\CodingAgent\Entity\ToolQuestionStatusEnum::Answered;

        $store2 = $this->createMock(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class);
        $store2->expects($this->once())
            ->method('findByRequestId')
            ->with($expectedRequestId2)
            ->willReturn($existingQuestion2);

        $dispatcher2 = new EventDispatcher();
        $dispatcher2->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry2, $store2, getcwd() ?: '/'));

        $result2 = (new RegistryBackedToolbox($registry2, $dispatcher2))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-dummy-2', 'dummy-tool2', [])
        );

        // Handler must NOT have run — 'Abort' → block('dummy_denied')
        $this->assertSame(0, $handler2->calls);
        $this->assertIsArray($result2->getResult());
        $this->assertSame(true, $result2->getResult()['denied'] ?? null);
        $this->assertSame('dummy_denied', $result2->getResult()['reason'] ?? null);
        $this->assertStringContainsString('Aborted', $result2->getResult()['message'] ?? '');
    }

    /**
     * Prove that ApprovalAnswerContextDTO carries runId and toolCallId
     * as first-class fields, so extensions do NOT need to re-stash them
     * in the details array at requireApproval() time.
     *
     * Test thesis: an extension implementing ApprovalAnswerHookInterface
     * can read runId/toolCallId from the context DTO. If the subscriber
     * fails to populate them, resolveApprovalAnswer receives null values
     * and this test fails.
     */
    public function testApprovalAnswerContextDtoReceivesRunIdAndToolCallIdFromSubscriber(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('handler_ran');
        $registry->registerTool('ctx-test-tool', 'Context test', [], $handler, 'ctx-test-tool');

        $hookRegistry = new ExtensionHookRegistry();
        $receivedContext = null;
        $hook = $this->approvalAwareHook(
            onToolCall: static function (ToolCallContextDTO $context): ToolCallDecisionDTO {
                return ToolCallDecisionDTO::requireApproval(
                    prompt: 'Allow?',
                    questionId: 'qid_ctx',
                );
            },
            resolveApprovalAnswer: function (ApprovalAnswerContextDTO $context) use (&$receivedContext): ToolCallDecisionDTO {
                $receivedContext = $context;

                return ToolCallDecisionDTO::allow();
            },
        );
        $hookRegistry->addToolCallHook($hook);

        $hookId = \hash('crc32b', $hook::class);
        $expectedRequestId = \sprintf('%s__call-ctx', $hookId);

        $existingQuestion = new \Ineersa\CodingAgent\Entity\ToolQuestion();
        $existingQuestion->requestId = $expectedRequestId;
        $existingQuestion->runId = 'run-ctx';
        $existingQuestion->toolCallId = 'call-ctx';
        $existingQuestion->toolName = 'ctx-test-tool';
        $existingQuestion->prompt = 'Allow?';
        $existingQuestion->kind = 'approval';
        $existingQuestion->answerText = 'Allow once';
        $existingQuestion->status = \Ineersa\CodingAgent\Entity\ToolQuestionStatusEnum::Answered;

        $store = $this->createMock(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class);
        $store->expects($this->once())
            ->method('findByRequestId')
            ->with($expectedRequestId)
            ->willReturn($existingQuestion);

        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber($hookRegistry, $store, getcwd() ?: '/'));

        $result = (new RegistryBackedToolbox($registry, $dispatcher))->execute(
            new \Symfony\AI\Platform\Result\ToolCall('call-ctx', 'ctx-test-tool', [])
        );

        // Handler must have run (Allow once → allow).
        $this->assertSame('handler_ran', $result->getResult());
        $this->assertSame(1, $handler->calls);

        // The extension received runId + toolCallId as first-class DTO fields
        // without needing to stash them in details.
        $this->assertNotNull($receivedContext, 'Expected resolveApprovalAnswer to be called with context');
        $this->assertSame('run-ctx', $receivedContext->runId);
        $this->assertSame('call-ctx', $receivedContext->toolCallId);
    }

    /**
     * Build a hook stub that implements both ToolCallHookInterface and
     * ApprovalAnswerHookInterface, allowing tests to control the
     * resolveApprovalAnswer outcome without needing the real SafeGuard hook.
     *
     * @param callable(ToolCallContextDTO): ToolCallDecisionDTO $onToolCall
     * @param callable(ApprovalAnswerContextDTO): ToolCallDecisionDTO $resolveApprovalAnswer
     */
    private function approvalAwareHook(
        callable $onToolCall,
        ?callable $resolveApprovalAnswer = null,
    ): ToolCallHookInterface&ApprovalAnswerHookInterface {
        return new class($onToolCall, $resolveApprovalAnswer) implements ToolCallHookInterface, ApprovalAnswerHookInterface {
            /**
             * @param callable(ToolCallContextDTO): ToolCallDecisionDTO $onToolCall
             * @param callable(ApprovalAnswerContextDTO): ToolCallDecisionDTO|null $resolveApprovalAnswer
             */
            public function __construct(
                private readonly mixed $onToolCall,
                private readonly mixed $resolveApprovalAnswer = null,
            ) {
            }

            public function onToolCall(ToolCallContextDTO $context): ToolCallDecisionDTO
            {
                return ($this->onToolCall)($context);
            }

            public function onApprovalAnswered(ApprovalAnswerContextDTO $context): void
            {
                // No-op for tests
            }

            public function resolveApprovalAnswer(ApprovalAnswerContextDTO $context): ToolCallDecisionDTO
            {
                if (null !== $this->resolveApprovalAnswer) {
                    return ($this->resolveApprovalAnswer)($context);
                }

                return ToolCallDecisionDTO::allow();
            }
        };
    }

    public function testRequireApprovalDeniedImmediatelyForNoninteractiveChildRun(): void
    {
        $registry = new ToolRegistry();
        $handler = $this->countingHandler('should not run');
        $registry->registerTool('approval-tool', 'Tool needing approval', [], $handler, 'approval-tool');

        $hookRegistry = new ExtensionHookRegistry();
        $hookRegistry->addToolCallHook($this->toolCallHook(static fn (): ToolCallDecisionDTO => ToolCallDecisionDTO::requireApproval(
            prompt: 'Allow?',
            questionId: 'qid_child',
            schema: ['type' => 'string', 'enum' => ['Allow once', 'Deny']],
            details: ['category' => 'destructive'],
        )));

        $store = $this->createMock(\Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface::class);
        $store->expects($this->never())->method('create');
        $store->expects($this->never())->method('pollAnswerText');

        $childEvent = new RunEvent(
            runId: 'child-run-1',
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'payload' => [
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'interactive' => false,
                        ],
                    ],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        );
        $childEventStore = $this->createStub(EventStoreInterface::class);
        $childEventStore->method('allFor')->willReturn([$childEvent]);
        $probe = new NoninteractiveChildRunProbe($childEventStore);

        $contextAccessor = new StackToolExecutionContextAccessor();
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new ExtensionToolHookEventSubscriber(
            $hookRegistry,
            $store,
            getcwd() ?: '/',
            $contextAccessor,
            noninteractiveChildProbe: $probe,
        ));

        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            defaultTimeoutSeconds: null,
            maxParallelism: 2,
            resultStore: new ToolExecutionResultStore(),
            toolbox: new RegistryBackedToolbox($registry, $dispatcher),
            contextAccessor: $contextAccessor,
        );

        $result = $contextAccessor->with(new \Ineersa\AgentCore\Application\Tool\ToolContext(
            runId: 'child-run-1',
            turnNo: 1,
            toolCallId: 'call-child-1',
            toolName: 'approval-tool',
            cancellationToken: new class implements \Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface {
                public function isCancellationRequested(): bool { return false; }
            },
            timeoutSeconds: null,
        ), fn () => $executor->execute(new ToolCall(
            toolCallId: 'call-child-1',
            toolName: 'approval-tool',
            arguments: ['cmd' => 'cat ~/.bashrc'],
            orderIndex: 0,
            runId: 'child-run-1',
            context: ['turn_no' => 1],
        )));

        $this->assertSame(0, $handler->calls);
        $this->assertIsArray($result->details['raw_result'] ?? null);
        $this->assertTrue($result->details['raw_result']['denied'] ?? null);
        $this->assertTrue($result->details['raw_result']['noninteractive_child_run'] ?? null);
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

    private function stubExecBridge(): ExecInterface
    {
        return new readonly class implements ExecInterface {
            public function exec(string $command, array $args = [], ?ExecOptionsDTO $options = null): ExecResultDTO
            {
                return new ExecResultDTO('', '', 0);
            }
        };
    }

    private function stubCommandRegistry(): CommandRegistryInterface
    {
        return new readonly class implements CommandRegistryInterface {
            public function register(CommandDefinitionDTO $definition, ExtensionCommandHandlerInterface $handler): void {}
        };
    }
}
