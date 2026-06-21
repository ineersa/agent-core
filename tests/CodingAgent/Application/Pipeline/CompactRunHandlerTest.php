<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\Pipeline;

use Ineersa\AgentCore\Contract\Compaction\CompactionPrepareResult;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Contract\Compaction\CompactResult;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\Pipeline\CompactRunHandler;
use Ineersa\CodingAgent\Compaction\BeforeCompactionHookInterface;
use Ineersa\CodingAgent\Compaction\CompactionHookContextDTO;
use Ineersa\CodingAgent\Compaction\CompactionHookDispatcher;
use Ineersa\CodingAgent\Compaction\CompactionHookResultDTO;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\ModelSettingsPersister;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\TuiConfig;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for {@see CompactRunHandler}.
 *
 * Theses:
 *  - Ready preparation emits context_compaction_started with activeStepId set.
 *  - Ready preparation dispatches ExecuteCompactionStep, preserves messages.
 *  - When ModelSelectionService returns a non-null active model and no compaction override,
 *    the started payload and dispatched step carry that active session model.
 *  - Non-ready preparation emits context_compaction_failed with structural reason, messages_replaced:false.
 *  - Non-ready preparation does NOT set activeStepId (no worker dispatched).
 *  - Failure messages use "Compaction failed" wording, never "skipped".
 */
final class CompactRunHandlerTest extends TestCase
{
    public function testReadyPreparationEmitsStartedAndDispatchesWorker(): void
    {
        $messages = [
            $this->userMsg('question 1'),
            $this->userMsg('question 2'),
            $this->assistantMsg('answer 1'),
            $this->assistantMsg('answer 2'),
        ];
        $state = $this->createRunState($messages);

        // Simulate a ready preparation: summarize first 2 messages, retain last 2.
        $summarize = [$messages[0], $messages[1]];
        $retained = [$messages[2], $messages[3]];

        $fakeService = $this->createReadyCompactionService($summarize, $retained, tokenEstimateBefore: 42000);
        $fakeModelSelection = $this->createModelSelectionStub();

        // Explicit compaction model override — prove it flows to both the
        // started event payload and the dispatched ExecuteCompactionStep.
        $appConfig = $this->createAppConfig(keepRecentTokens: 20000, compactionModel: 'openai/compaction-model');

        $handler = new CompactRunHandler(
            $fakeService,
            $appConfig,
            new EventFactory(),
            $fakeModelSelection,
            new CompactionHookDispatcher([]),
        );

        $result = $handler->handle(
            new CompactRun(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                trigger: 'manual',
            ),
            $state,
        );

        // Emits context_compaction_started.
        $this->assertNotNull($result->nextState);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ContextCompactionStarted->value, $result->events[0]->type);

        $payload = $result->events[0]->payload;
        $this->assertSame('step-1', $payload['step_id']);
        $this->assertSame('manual', $payload['trigger']);
        $this->assertSame('openai/compaction-model', $payload['model'], 'Explicit compaction model override must appear in started payload');
        $this->assertSame(42000, $payload['estimated_tokens']);
        $this->assertSame(20000, $payload['keep_recent_tokens']);
        $this->assertSame(4, $payload['messages_before']);
        $this->assertSame(2, $payload['messages_to_summarize']);
        $this->assertSame(2, $payload['messages_retained']);

        // Sets activeStepId so the result handler can match the result.
        $this->assertSame('step-1', $result->nextState->activeStepId);

        // Dispatches ExecuteCompactionStep effect.
        $this->assertCount(1, $result->effects);
        $this->assertInstanceOf(ExecuteCompactionStep::class, $result->effects[0]);

        /** @var ExecuteCompactionStep $workerMsg */
        $workerMsg = $result->effects[0];
        $this->assertSame('run-1', $workerMsg->runId());
        $this->assertSame('openai/compaction-model', $workerMsg->model, 'Explicit compaction model override must be dispatched to worker');
        $this->assertSame('manual', $workerMsg->trigger);

        // Messages are NOT mutated in started handler (only in compacted/failed).
        $this->assertCount(\count($messages), $result->nextState->messages);
    }

    /**
     * Thesis: when no explicit compaction model override is configured but
     * no active session model is available (getCurrentModel returns null),
     * the started payload and dispatched step use the empty-string sentinel.
     * SessionAwareModelResolver treats '' as "no override" and resolves
     * from provider defaults.
     *
     * Full DB integration: verifying getCurrentModel returns a non-null
     * session model requires a test kernel + HatfieldSessionStore with a
     * populated hatfield_session row.  The resolver path is covered by
     * {@see SessionAwareModelResolverTest}.
     */
    public function testReadyPreparationUsesEmptyModelWhenNoOverrideAndNoSessionModel(): void
    {
        $messages = [
            $this->userMsg('question 1'),
            $this->userMsg('question 2'),
            $this->assistantMsg('answer 1'),
            $this->assistantMsg('answer 2'),
        ];
        $state = $this->createRunState($messages);

        $summarize = [$messages[0], $messages[1]];
        $retained = [$messages[2], $messages[3]];

        $fakeService = $this->createReadyCompactionService($summarize, $retained, tokenEstimateBefore: 42000);
        $fakeModelSelection = $this->createModelSelectionStub();

        // No explicit compaction model — resolved model is null (getCurrentModel also returns null).
        $appConfig = $this->createAppConfig(keepRecentTokens: 20000);

        $handler = new CompactRunHandler(
            $fakeService,
            $appConfig,
            new EventFactory(),
            $fakeModelSelection,
            new CompactionHookDispatcher([]),
        );

        $result = $handler->handle(
            new CompactRun(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                trigger: 'manual',
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertCount(1, $result->events);
        $payload = $result->events[0]->payload;

        // When no model override and no session model, the payload contains
        // the resolved model (null) and the worker receives the empty-string
        // sentinel that SessionAwareModelResolver treats as "no override".
        $this->assertNull($payload['model'] ?? null);

        /** @var ExecuteCompactionStep $workerMsg */
        $workerMsg = $result->effects[0];
        $this->assertSame('', $workerMsg->model);
    }

    public function testNonReadyPreparationEmitsFailedAndPreservesMessages(): void
    {
        $messages = [$this->userMsg('hi'), $this->assistantMsg('hello')];
        $state = $this->createRunState($messages);

        $fakeService = $this->createFailedCompactionService('too_few_messages');
        $fakeModelSelection = $this->createModelSelectionStub();

        $appConfig = $this->createAppConfig();

        $handler = new CompactRunHandler(
            $fakeService,
            $appConfig,
            new EventFactory(),
            $fakeModelSelection,
            new CompactionHookDispatcher([]),
        );

        $result = $handler->handle(
            new CompactRun(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                trigger: 'manual',
            ),
            $state,
        );

        // Emits context_compaction_failed.
        $this->assertNotNull($result->nextState);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $result->events[0]->type);

        $payload = $result->events[0]->payload;
        $this->assertSame('too_few_messages', $payload['reason']);
        $this->assertFalse($payload['messages_replaced']);

        // Messages preserved.
        $this->assertCount(\count($messages), $result->nextState->messages);
        $this->assertNotEmpty($result->nextState->messages);
        $this->assertSame('hi', $result->nextState->messages[0]->content[0]['text'] ?? null);

        // Does NOT set activeStepId (no worker was dispatched).
        $this->assertNull($result->nextState->activeStepId);

        // No worker dispatched.
        $this->assertEmpty($result->effects);
    }

    public function testFailureMessagesUseFailedWordingNotSkipped(): void
    {
        $messages = [$this->userMsg('hi')];
        $state = $this->createRunState($messages);

        $reasonMessages = [
            'too_few_messages' => 'there are not enough messages to compact',
            'below_keep_recent_tokens' => 'there is no older context outside the retained tail',
            'no_boundary' => 'could not determine a boundary',
            'no_safe_boundary' => 'no safe boundary found',
            'invalid_message_sequence' => 'Compaction failed: invalid_message_sequence', // unknown → generic
        ];

        foreach ($reasonMessages as $reason => $expectedSubstring) {
            $fakeService = $this->createFailedCompactionService($reason);
            $fakeModelSelection = $this->createModelSelectionStub();

            $appConfig = $this->createAppConfig();

            $handler = new CompactRunHandler(
                $fakeService,
                $appConfig,
                new EventFactory(),
                $fakeModelSelection,
                new CompactionHookDispatcher([]),
            );

            $result = $handler->handle(
                new CompactRun(
                    runId: 'run-1',
                    turnNo: 5,
                    stepId: 'step-1',
                    attempt: 1,
                    idempotencyKey: 'key-1',
                    trigger: 'manual',
                ),
                $state,
            );

            $payload = $result->events[0]->payload;

            // Must use "failed" wording, never "skipped".
            $this->assertStringContainsString(
                $expectedSubstring,
                $payload['message'],
                \sprintf('Reason "%s" should contain "%s", got: %s', $reason, $expectedSubstring, $payload['message']),
            );
            $this->assertStringNotContainsString('skipped', $payload['message'], 'Message must not contain "skipped".');
        }
    }

    // ── Hook integration tests ──

    /**
     * Thesis: when a before-compaction hook cancels, the handler emits
     * context_compaction_failed with reason prefixed 'hook_cancelled:',
     * does NOT set activeStepId, and dispatches NO worker effects.
     */
    public function testHookCancelEmitsFailedWithNoWorkerDispatch(): void
    {
        $messages = [
            $this->userMsg('question 1'),
            $this->userMsg('question 2'),
        ];
        $state = $this->createRunState($messages);

        $fakeService = $this->createReadyCompactionService(
            [$messages[0]],
            [$messages[1]],
            tokenEstimateBefore: 5000,
        );

        $cancelHook = new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                return CompactionHookResultDTO::cancel('SafeGuard: session blocked.');
            }
        };

        $handler = new CompactRunHandler(
            $fakeService,
            $this->createAppConfig(),
            new EventFactory(),
            $this->createModelSelectionStub(),
            new CompactionHookDispatcher([$cancelHook]),
        );

        $result = $handler->handle(
            new CompactRun(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                trigger: 'manual',
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $result->events[0]->type);

        $payload = $result->events[0]->payload;
        $this->assertStringContainsString('hook_cancelled:', $payload['reason']);
        $this->assertStringContainsString('SafeGuard: session blocked.', $payload['message']);
        $this->assertFalse($payload['messages_replaced']);

        // No worker dispatched.
        $this->assertEmpty($result->effects);

        // activeStepId NOT set (null means preserve, so unchanged from initial null).
        $this->assertNull($result->nextState->activeStepId);

        // Messages preserved.
        $this->assertCount(\count($messages), $result->nextState->messages);
    }

    /**
     * Thesis: when a hook provides a replacement summary, the handler skips
     * the LLM call entirely, emits context_compaction_started + context_compacted,
     * rewrites RunState.messages, and dispatches NO worker.
     */
    public function testHookReplacementSummaryEmitsStartedAndCompactedWithoutWorker(): void
    {
        $messages = [
            $this->userMsg('question 1'),
            $this->userMsg('question 2'),
        ];
        $state = $this->createRunState($messages);

        $summarize = [$messages[0]];
        $retained = [$messages[1]];

        $replacementText = 'Custom replacement summary from hook.';
        $compactedMessages = [
            new AgentMessage('user', [['type' => 'text', 'text' => $replacementText]], metadata: ['compact_summary' => true]),
            ...$retained,
        ];

        $fakeService = $this->createReadyCompactionServiceWithBuildCompactedMessages(
            summarizeMessages: $summarize,
            retainedMessages: $retained,
            compactedMessages: $compactedMessages,
            tokenEstimateBefore: 5000,
            tokenEstimateAfter: 2000,
        );

        $replaceHook = new class($replacementText) implements BeforeCompactionHookInterface {
            public function __construct(private string $text)
            {
            }

            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                return CompactionHookResultDTO::replaceSummary($this->text);
            }
        };

        $handler = new CompactRunHandler(
            $fakeService,
            $this->createAppConfig(),
            new EventFactory(),
            $this->createModelSelectionStub(),
            new CompactionHookDispatcher([$replaceHook]),
        );

        $result = $handler->handle(
            new CompactRun(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                trigger: 'manual',
            ),
            $state,
        );

        // Two events: started + compacted.
        $this->assertNotNull($result->nextState);
        $this->assertCount(2, $result->events);
        $this->assertSame(RunEventTypeEnum::ContextCompactionStarted->value, $result->events[0]->type);
        $this->assertSame(RunEventTypeEnum::ContextCompacted->value, $result->events[1]->type);

        // Started payload includes replacement_summary=true.
        $startedPayload = $result->events[0]->payload;
        $this->assertTrue($startedPayload['replacement_summary'] ?? false);

        // Compacted payload contains the replacement summary text.
        $compactedPayload = $result->events[1]->payload;
        $this->assertSame($replacementText, $compactedPayload['summary_text']);
        $this->assertTrue($compactedPayload['replacement_summary'] ?? false);

        // Messages replaced with compacted list.
        $this->assertSame($compactedMessages, $result->nextState->messages);

        // No worker dispatched.
        $this->assertEmpty($result->effects);

        // activeStepId cleared on compacted state.
        $this->assertNull($result->nextState->activeStepId);
    }

    /**
     * Thesis: when a hook appends additional instructions, they reach
     * buildSummarizationMessages alongside the original custom instructions.
     */
    public function testHookAdditionalInstructionsPassedToSummarizationBuilder(): void
    {
        $messages = [
            $this->userMsg('question 1'),
            $this->userMsg('question 2'),
        ];
        $state = $this->createRunState($messages);

        $receivedInstructions = null;

        $fakeService = new class($receivedInstructions) implements CompactionServiceInterface {
            public ?string $receivedInstructions = null;

            public function prepare(array $messages): CompactionPrepareResult
            {
                return CompactionPrepareResult::ready(
                    messagesToSummarize: \array_slice($messages, 0, 1),
                    retainedTailMessages: \array_slice($messages, 1),
                    tokenEstimateBefore: 5000,
                    messagesCompacted: 1,
                    messagesRetained: 1,
                    firstRetainedIndex: 1,
                    priorSummaryPresent: false,
                );
            }

            public function buildSummarizationMessages(CompactionPrepareResult $result, ?string $customInstructions): array
            {
                $this->receivedInstructions = $customInstructions;

                return [];
            }

            public function buildCompactedMessages(string $summaryText, CompactionPrepareResult $result): CompactResult
            {
                throw new \LogicException('Not expected in this test.');
            }
        };

        $hook = new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                return new CompactionHookResultDTO(
                    additionalInstructions: 'Hook instruction: focus on database decisions.',
                );
            }
        };

        $handler = new CompactRunHandler(
            $fakeService,
            $this->createAppConfig(),
            new EventFactory(),
            $this->createModelSelectionStub(),
            new CompactionHookDispatcher([$hook]),
        );

        $handler->handle(
            new CompactRun(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                trigger: 'manual',
                customInstructions: 'Original instruction.',
            ),
            $state,
        );

        $this->assertNotNull($fakeService->receivedInstructions);
        $this->assertStringContainsString('Original instruction.', $fakeService->receivedInstructions);
        $this->assertStringContainsString('Hook instruction: focus on database decisions.', $fakeService->receivedInstructions);
    }

    /**
     * Thesis: hook metadata is present in context_compaction_started payload
     * when compaction proceeds normally (async LLM path).
     */
    public function testHookMetadataPresentInStartedPayload(): void
    {
        $messages = [
            $this->userMsg('question 1'),
            $this->userMsg('question 2'),
        ];
        $state = $this->createRunState($messages);

        $fakeService = $this->createReadyCompactionService(
            [$messages[0]],
            [$messages[1]],
            tokenEstimateBefore: 5000,
        );

        $hook = new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                return new CompactionHookResultDTO(
                    metadata: ['hook_name' => 'test-hook', 'hook_version' => 2],
                );
            }
        };

        $handler = new CompactRunHandler(
            $fakeService,
            $this->createAppConfig(),
            new EventFactory(),
            $this->createModelSelectionStub(),
            new CompactionHookDispatcher([$hook]),
        );

        $result = $handler->handle(
            new CompactRun(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                trigger: 'manual',
            ),
            $state,
        );

        $payload = $result->events[0]->payload;
        $this->assertArrayHasKey('hook_metadata', $payload);
        $this->assertSame('test-hook', $payload['hook_metadata']['hook_name'] ?? null);
        $this->assertSame(2, $payload['hook_metadata']['hook_version'] ?? null);
    }

    /**
     * Thesis: when a hook cancels with metadata, the metadata appears in
     * the context_compaction_failed event payload.
     */
    public function testHookCancelMetadataPresentInFailedPayload(): void
    {
        $messages = [$this->userMsg('hi')];
        $state = $this->createRunState($messages);

        $fakeService = $this->createReadyCompactionService(
            [$messages[0]],
            [],
            tokenEstimateBefore: 100,
        );

        $cancelHook = new class implements BeforeCompactionHookInterface {
            public function beforeCompaction(CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                return new CompactionHookResultDTO(
                    cancelReason: 'rate-limited',
                    metadata: ['limit_type' => 'daily', 'reset_at' => 86400],
                );
            }
        };

        $handler = new CompactRunHandler(
            $fakeService,
            $this->createAppConfig(),
            new EventFactory(),
            $this->createModelSelectionStub(),
            new CompactionHookDispatcher([$cancelHook]),
        );

        $result = $handler->handle(
            new CompactRun(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                trigger: 'manual',
            ),
            $state,
        );

        $payload = $result->events[0]->payload;
        $this->assertArrayHasKey('hook_metadata', $payload);
        $this->assertSame('daily', $payload['hook_metadata']['limit_type'] ?? null);
        $this->assertSame(86400, $payload['hook_metadata']['reset_at'] ?? null);
        $this->assertStringContainsString('hook_cancelled:', $payload['reason']);
    }

    // ── helpers ──

    /**
     * @param list<AgentMessage> $messages
     */
    private function createRunState(array $messages): RunState
    {
        return new RunState(
            runId: 'run-1',
            status: RunStatus::Running,
            version: 10,
            turnNo: 5,
            lastSeq: 20,
            messages: $messages,
        );
    }

    /**
     * Creates a fake CompactionServiceInterface that returns a ready preparation.
     *
     * @param list<AgentMessage> $summarizeMessages Messages to summarize
     * @param list<AgentMessage> $retainedMessages  Messages in retained tail
     */
    private function createReadyCompactionService(
        array $summarizeMessages,
        array $retainedMessages,
        int $tokenEstimateBefore = 50000,
    ): CompactionServiceInterface {
        return new class($summarizeMessages, $retainedMessages, $tokenEstimateBefore) implements CompactionServiceInterface {
            public function __construct(
                private array $summarizeMessages,
                private array $retainedMessages,
                private int $tokenEstimateBefore,
            ) {
            }

            public function prepare(array $messages): CompactionPrepareResult
            {
                return CompactionPrepareResult::ready(
                    messagesToSummarize: $this->summarizeMessages,
                    retainedTailMessages: $this->retainedMessages,
                    tokenEstimateBefore: $this->tokenEstimateBefore,
                    messagesCompacted: \count($this->summarizeMessages),
                    messagesRetained: \count($this->retainedMessages),
                    firstRetainedIndex: 0,
                    priorSummaryPresent: false,
                );
            }

            public function buildSummarizationMessages(CompactionPrepareResult $result, ?string $customInstructions): array
            {
                return $this->summarizeMessages;
            }

            public function buildCompactedMessages(string $summaryText, CompactionPrepareResult $result): CompactResult
            {
                throw new \LogicException('Not expected in this test.');
            }
        };
    }

    private function createFailedCompactionService(string $failureReason): CompactionServiceInterface
    {
        return new class($failureReason) implements CompactionServiceInterface {
            public function __construct(private string $failureReason)
            {
            }

            public function prepare(array $messages): CompactionPrepareResult
            {
                return CompactionPrepareResult::failed($this->failureReason);
            }

            public function buildSummarizationMessages(CompactionPrepareResult $result, ?string $customInstructions): array
            {
                throw new \LogicException('Not expected in this test.');
            }

            public function buildCompactedMessages(string $summaryText, CompactionPrepareResult $result): CompactResult
            {
                throw new \LogicException('Not expected in this test.');
            }
        };
    }

    /**
     * Creates a fake CompactionServiceInterface that returns a ready preparation
     * AND supports buildCompactedMessages (needed for replacement summary hook tests).
     *
     * @param list<AgentMessage> $summarizeMessages
     * @param list<AgentMessage> $retainedMessages
     * @param list<AgentMessage> $compactedMessages Messages returned by buildCompactedMessages
     */
    private function createReadyCompactionServiceWithBuildCompactedMessages(
        array $summarizeMessages,
        array $retainedMessages,
        array $compactedMessages,
        int $tokenEstimateBefore = 50000,
        int $tokenEstimateAfter = 30000,
    ): CompactionServiceInterface {
        return new class($summarizeMessages, $retainedMessages, $compactedMessages, $tokenEstimateBefore, $tokenEstimateAfter) implements CompactionServiceInterface {
            public function __construct(
                private array $summarizeMessages,
                private array $retainedMessages,
                private array $compactedMessages,
                private int $tokenEstimateBefore,
                private int $tokenEstimateAfter,
            ) {
            }

            public function prepare(array $messages): CompactionPrepareResult
            {
                return CompactionPrepareResult::ready(
                    messagesToSummarize: $this->summarizeMessages,
                    retainedTailMessages: $this->retainedMessages,
                    tokenEstimateBefore: $this->tokenEstimateBefore,
                    messagesCompacted: \count($this->summarizeMessages),
                    messagesRetained: \count($this->retainedMessages),
                    firstRetainedIndex: 0,
                    priorSummaryPresent: false,
                );
            }

            public function buildSummarizationMessages(CompactionPrepareResult $result, ?string $customInstructions): array
            {
                return $this->summarizeMessages;
            }

            public function buildCompactedMessages(string $summaryText, CompactionPrepareResult $result): CompactResult
            {
                return new CompactResult(
                    summaryText: $summaryText,
                    summaryMessage: $this->compactedMessages[0],
                    compactedMessages: $this->compactedMessages,
                    tokenEstimateBefore: $this->tokenEstimateBefore,
                    tokenEstimateAfter: $this->tokenEstimateAfter,
                    messagesCompacted: \count($this->summarizeMessages),
                    messagesRetained: \count($this->retainedMessages),
                    firstRetainedIndex: [] !== $this->retainedMessages ? $this->retainedIndexFromMessages() : 0,
                );
            }

            private function retainedIndexFromMessages(): int
            {
                // For test simplicity: count summarize messages as the offset.
                return \count($this->summarizeMessages);
            }
        };
    }

    private function createAppConfig(int $keepRecentTokens = 20000, ?string $compactionModel = null): AppConfig
    {
        return new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: '/',
            compaction: new CompactionConfig(
                keepRecentTokens: $keepRecentTokens,
                model: $compactionModel,
            ),
        );
    }

    /**
     * Stub ModelSelectionService that returns null for getCurrentModel().
     *
     * ModelSelectionService is final and cannot be mocked with PHPUnit.
     * We construct it with a real AppConfig (no AI section → null catalog)
     * and a Reflection-built ModelResolver stub.  Because the catalog is
     * null, getCurrentModel() returns null without touching the DB, so the
     * fake SessionMetadataStore / HatfieldSessionStore are never accessed.
     *
     * This stub lets us test the handler's structural event/effect
     * emissions without a full test kernel.  The real model resolution
     * chain is tested separately in SessionAwareModelResolverTest.
     */
    private function createModelSelectionStub(): ModelSelectionService
    {
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: '/',
        );

        // SessionMetadataStore is never accessed (catalog null → early return).
        $sessionMetaRc = new \ReflectionClass(SessionMetadataStore::class);
        $sessionMetaStore = $sessionMetaRc->newInstanceWithoutConstructor();

        $modelResolver = new ModelResolver($appConfig, $sessionMetaStore);

        // ModelSettingsPersister is never accessed by getCurrentModel().
        $persisterRc = new \ReflectionClass(ModelSettingsPersister::class);
        $persister = $persisterRc->newInstanceWithoutConstructor();

        return new ModelSelectionService($appConfig, $modelResolver, $persister);
    }

    private function userMsg(string $text): AgentMessage
    {
        return new AgentMessage('user', [['type' => 'text', 'text' => $text]]);
    }

    private function assistantMsg(string $text): AgentMessage
    {
        return new AgentMessage('assistant', [['type' => 'text', 'text' => $text]]);
    }
}
