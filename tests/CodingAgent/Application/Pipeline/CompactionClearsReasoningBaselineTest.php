<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\Pipeline;

use Ineersa\AgentCore\Contract\Compaction\CompactionPrepareResult;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Contract\Compaction\CompactResult;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\CompactRun;
use Ineersa\AgentCore\Domain\Run\CurrentOperationDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Application\Pipeline\CompactionStepResultHandler;
use Ineersa\CodingAgent\Application\Pipeline\CompactRunHandler;
use Ineersa\CodingAgent\Compaction\BeforeCompactionHookInterface;
use Ineersa\CodingAgent\Compaction\CompactionHookDispatcher;
use Ineersa\CodingAgent\Compaction\CompactionHookResultDTO;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\CompactionConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Extension\ExtensionCompactionHookDispatcher;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\StubRunRelationshipReader;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Uid\UuidV7;

final class CompactionClearsReasoningBaselineTest extends IsolatedKernelTestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('compaction-baseline', 0o750);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testSuccessfulCompactionClearsReasoningBaselineWhilePreservingSelection(): void
    {
        $em = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $store = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
            ),
            entityManager: $em,
            dispatcher: new EventDispatcher(),
        );

        $entity = new HatfieldSession();
        $entity->cwd = $this->tempDir.'/project';
        $entity->model = 'openai-codex/gpt-6-astra';
        $entity->reasoning = 'high';
        $entity->providerCacheKey = UuidV7::v7()->toRfc4122();
        $em->persist($entity);
        $em->flush();
        $sessionId = (string) $entity->id;

        $this->assertNull($store->claimReasoningBaseline($sessionId, 'openai-codex/gpt-6-astra', 'medium'));
        $store->rememberReasoningTransition(
            $sessionId,
            'openai-codex/gpt-6-astra',
            hash('sha256', 'user||hello|0'),
            'high',
        );
        $this->assertNotNull($store->findSession($sessionId)?->reasoningBaseline);
        $this->assertSame('high', $store->findSession($sessionId)?->reasoning);

        $summary = new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'summary']]);
        $retained = [new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'recent']])];
        $handler = new CompactionStepResultHandler(
            $this->stubCompactionService([$summary, ...$retained]),
            new EventFactory(),
            $store,
        );

        $state = new RunState(
            runId: $sessionId,
            status: RunStatus::Compacting,
            version: 10,
            turnNo: 5,
            lastSeq: 20,
            messages: [
                new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'old']]),
            ],
            activeStepId: 'step-1',
            currentOperation: new CurrentOperationDTO(5, 'step-1', 1, 'key-1'),
            model: 'openai-codex/gpt-6-astra',
        );

        $result = $handler->handle(
            new CompactionStepResult(
                runId: $sessionId,
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                summaryText: 'summary',
                error: null,
                retainedTailMessages: $retained,
                messagesCompacted: 1,
                messagesRetained: 1,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'manual',
                model: 'openai-codex/gpt-6-astra',
                modelOptions: ['thinking_level' => 'high'],
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunEventTypeEnum::ContextCompacted->value, $result->events[0]->type);
        $this->assertSame(
            ['continuation_generation' => $store->continuationGeneration($sessionId)],
            $store->findSession($sessionId)?->reasoningBaseline,
        );
        $this->assertSame('high', $store->findSession($sessionId)?->reasoning);
    }

    public function testReplacementSummaryClearsReasoningBaselineSoNextRequestRebaselinesSelectedEffort(): void
    {
        $em = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $store = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
            ),
            entityManager: $em,
            dispatcher: new EventDispatcher(),
        );

        $entity = new HatfieldSession();
        $entity->cwd = $this->tempDir.'/project';
        $entity->model = 'openai-codex/gpt-6-astra';
        $entity->reasoning = 'high';
        $entity->providerCacheKey = UuidV7::v7()->toRfc4122();
        $em->persist($entity);
        $em->flush();
        $sessionId = (string) $entity->id;

        $this->assertNull($store->claimReasoningBaseline($sessionId, 'openai-codex/gpt-6-astra', 'medium'));
        $this->assertSame('high', $store->claimReasoningBaseline($sessionId, 'openai-codex/gpt-6-astra', 'high')['update'] ?? null);
        $store->rememberReasoningTransition(
            $sessionId,
            'openai-codex/gpt-6-astra',
            hash('sha256', 'user||old-anchor|0'),
            'high',
        );
        $this->assertNotSame([], $store->listReasoningTransitions($sessionId, 'openai-codex/gpt-6-astra'));

        $history = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'old-anchor']]),
            new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'reply']]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'recent']]),
            new AgentMessage(role: 'assistant', content: [['type' => 'text', 'text' => 'more']]),
        ];
        $replacementText = 'replacement summary';
        $compacted = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => $replacementText]], metadata: ['compact_summary' => true]),
            $history[2],
            $history[3],
        ];

        $replaceHook = new class($replacementText) implements BeforeCompactionHookInterface {
            public function __construct(private string $replacementText)
            {
            }

            public function beforeCompaction(\Ineersa\CodingAgent\Compaction\CompactionHookContextDTO $context): CompactionHookResultDTO
            {
                return new CompactionHookResultDTO(replacementSummary: $this->replacementText);
            }
        };

        $handler = new CompactRunHandler(
            $this->stubCompactionService($compacted, summarizeCount: 2, retainedCount: 2),
            new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
                compaction: new CompactionConfig(keepRecentTokens: 20000),
            ),
            new EventFactory(),
            new CompactionHookDispatcher([$replaceHook]),
            new ExtensionCompactionHookDispatcher(new ExtensionHookRegistry(), new CompactionHookDispatcher([$replaceHook]), new NullLogger()),
            StubRunRelationshipReader::topLevel($sessionId),
            AttributeSerializerValidatorTestFactory::create()[0],
            $store,
        );

        $result = $handler->handle(
            new CompactRun(
                runId: $sessionId,
                turnNo: 5,
                stepId: 'step-replace',
                attempt: 1,
                idempotencyKey: 'key-replace',
                trigger: 'manual',
            ),
            new RunState(
                runId: $sessionId,
                status: RunStatus::Running,
                version: 10,
                turnNo: 5,
                lastSeq: 20,
                messages: $history,
                model: 'openai-codex/gpt-6-astra',
            ),
        );

        $this->assertSame(RunEventTypeEnum::ContextCompacted->value, $result->events[1]->type ?? null);
        $this->assertSame(
            ['continuation_generation' => $store->continuationGeneration($sessionId)],
            $store->findSession($sessionId)?->reasoningBaseline,
        );
        $this->assertSame('high', $store->findSession($sessionId)?->reasoning);
        $this->assertSame([], $store->listReasoningTransitions($sessionId, 'openai-codex/gpt-6-astra'));

        // Next request must re-establish baseline at the still-selected effort
        // instead of replaying the discarded high transition.
        $this->assertNull($store->claimReasoningBaseline($sessionId, 'openai-codex/gpt-6-astra', 'high'));
        $this->assertNull($store->claimReasoningBaseline($sessionId, 'openai-codex/gpt-6-astra', 'high')['update'] ?? null);
    }

    /**
     * @param list<AgentMessage> $compactedMessages
     */
    private function stubCompactionService(array $compactedMessages, int $summarizeCount = 1, int $retainedCount = 1): CompactionServiceInterface
    {
        return new class($compactedMessages, $summarizeCount, $retainedCount) implements CompactionServiceInterface {
            /** @param list<AgentMessage> $compacted */
            public function __construct(
                private array $compacted,
                private int $summarizeCount,
                private int $retainedCount,
            ) {
            }

            public function prepare(array $messages): CompactionPrepareResult
            {
                $summarize = \array_slice($messages, 0, $this->summarizeCount);
                $retained = \array_slice($messages, -$this->retainedCount);

                return CompactionPrepareResult::ready(
                    messagesToSummarize: $summarize,
                    retainedTailMessages: $retained,
                    tokenEstimateBefore: 50000,
                    messagesCompacted: $this->summarizeCount,
                    messagesRetained: $this->retainedCount,
                    firstRetainedIndex: max(0, \count($messages) - $this->retainedCount),
                    priorSummaryPresent: false,
                );
            }

            public function buildSummarizationMessages(CompactionPrepareResult $result, ?string $customInstructions): array
            {
                return $result->messagesToSummarize;
            }

            public function buildCompactedMessages(string $summaryText, CompactionPrepareResult $result): CompactResult
            {
                return new CompactResult(
                    compactedMessages: $this->compacted,
                    tokenEstimateBefore: 50000,
                    tokenEstimateAfter: 10000,
                    messagesCompacted: $this->summarizeCount,
                    messagesRetained: $this->retainedCount,
                    firstRetainedIndex: 0,
                );
            }

            public function compactMessages(
                string $runId,
                int $turnNo,
                array $messages,
                string $trigger = 'manual',
                ?string $customInstructions = null,
                ?string $activeModel = null,
            ): \Ineersa\AgentCore\Contract\Compaction\MessageSnapshotCompactionResult {
                throw new \LogicException('compactMessages not expected in this test path.');
            }
        };
    }
}
