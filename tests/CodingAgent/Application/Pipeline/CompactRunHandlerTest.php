<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Contract\Compaction\CompactionPrepareResult;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\Pipeline\CompactRunHandler;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\ModelSettingsPersister;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Contract tests for {@see CompactRunHandler}.
 *
 * Theses:
 *  - Ready preparation emits context_compaction_started with activeStepId set.
 *  - Ready preparation dispatches ExecuteCompactionStep, preserves messages.
 *  - Non-ready preparation emits context_compaction_failed with structural reason, preserves messages.
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

        // Simulate a ready preparation.
        $summarize = [$this->userMsg('summary input')];
        $retained = $messages;

        $fakeService = $this->createReadyCompactionService($summarize, $retained, tokenEstimateBefore: 42000);
        $fakeModelSelection = $this->createModelSelectionStub();

        $appConfig = $this->createAppConfig(keepRecentTokens: 20000);

        $handler = new CompactRunHandler(
            $fakeService,
            $appConfig,
            new EventFactory(),
            $fakeModelSelection,
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
        self::assertNotNull($result->nextState);
        self::assertCount(1, $result->events);
        self::assertSame(RunEventTypeEnum::ContextCompactionStarted->value, $result->events[0]->type);

        $payload = $result->events[0]->payload;
        self::assertSame('manual', $payload['trigger']);
        self::assertArrayHasKey('model', $payload);
        self::assertSame(42000, $payload['estimated_tokens']);
        self::assertSame(20000, $payload['keep_recent_tokens']);
        self::assertSame(4, $payload['messages_before']);
        self::assertSame(1, $payload['messages_to_summarize']);
        self::assertSame(4, $payload['messages_retained']);

        // Sets activeStepId so the result handler can match the result.
        self::assertSame('step-1', $result->nextState->activeStepId);

        // Dispatches ExecuteCompactionStep effect.
        self::assertCount(1, $result->effects);
        self::assertInstanceOf(ExecuteCompactionStep::class, $result->effects[0]);

        /** @var ExecuteCompactionStep $workerMsg */
        $workerMsg = $result->effects[0];
        self::assertSame('run-1', $workerMsg->runId());
        self::assertSame('manual', $workerMsg->trigger);

        // Messages are NOT mutated in started handler (only in compacted/failed).
        self::assertCount(\count($messages), $result->nextState->messages);
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
        self::assertNotNull($result->nextState);
        self::assertCount(1, $result->events);
        self::assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $result->events[0]->type);

        $payload = $result->events[0]->payload;
        self::assertSame('too_few_messages', $payload['reason']);
        self::assertTrue($payload['preserved_messages']);

        // Messages preserved.
        self::assertCount(\count($messages), $result->nextState->messages);
        self::assertNotEmpty($result->nextState->messages);
        self::assertSame('hi', $result->nextState->messages[0]->content[0]['text'] ?? null);

        // Does NOT set activeStepId (no worker was dispatched).
        self::assertNull($result->nextState->activeStepId);

        // No worker dispatched.
        self::assertEmpty($result->effects);
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
            self::assertStringContainsString(
                $expectedSubstring,
                $payload['message'],
                \sprintf('Reason "%s" should contain "%s", got: %s', $reason, $expectedSubstring, $payload['message']),
            );
            self::assertStringNotContainsString('skipped', $payload['message'], 'Message must not contain "skipped".');
        }
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
            ) {}

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

            public function buildCompactedMessages(string $summaryText, CompactionPrepareResult $result): \Ineersa\AgentCore\Contract\Compaction\CompactResult
            {
                throw new \LogicException('Not expected in this test.');
            }
        };
    }

    private function createFailedCompactionService(string $failureReason): CompactionServiceInterface
    {
        return new class($failureReason) implements CompactionServiceInterface {
            public function __construct(private string $failureReason) {}

            public function prepare(array $messages): CompactionPrepareResult
            {
                return CompactionPrepareResult::failed($this->failureReason);
            }

            public function buildSummarizationMessages(CompactionPrepareResult $result, ?string $customInstructions): array
            {
                throw new \LogicException('Not expected in this test.');
            }

            public function buildCompactedMessages(string $summaryText, CompactionPrepareResult $result): \Ineersa\AgentCore\Contract\Compaction\CompactResult
            {
                throw new \LogicException('Not expected in this test.');
            }
        };
    }

    private function createAppConfig(int $keepRecentTokens = 20000): AppConfig
    {
        return new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: '/',
            compaction: new CompactionConfig(keepRecentTokens: $keepRecentTokens),
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
        $sessionMetaRc = new ReflectionClass(SessionMetadataStore::class);
        $sessionMetaStore = $sessionMetaRc->newInstanceWithoutConstructor();

        $modelResolver = new ModelResolver($appConfig, $sessionMetaStore);

        // ModelSettingsPersister is never accessed by getCurrentModel().
        $persisterRc = new ReflectionClass(ModelSettingsPersister::class);
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
