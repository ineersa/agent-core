<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationOptions;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderCompatibilityRequestShaper;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderRequestPreparer;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ReasoningOptionsFeatureShaper;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader;
use Ineersa\CodingAgent\Agent\Execution\ChatGPTReasoningTransitionRequestHook;
use Ineersa\CodingAgent\Agent\Execution\ChatGPTReasoningTransitionTransformHook;
use Ineersa\CodingAgent\Agent\Execution\SessionAwareModelResolver;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ChatGPT\ChatGPTSymfonyAiProviderBuilder;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\ChatGPTAuthFixture;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthStorageInterface;
use Symfony\AI\Platform\Platform;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Uid\Uuid;

final class ChatGPTReasoningTransitionRequestHookTest extends IsolatedKernelTestCase
{
    private string $directory;
    private string $sessionId;
    private AppConfig $config;
    private HatfieldSessionStore $sessions;
    /** @var list<array<string, mixed>> */
    private array $bodies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = TestDirectoryIsolation::createProjectTempDir('chatgpt-effort');
        $ai = AiConfig::optionalFromArray(['ai' => ['default_model' => 'openai-codex/gpt-6-sol', 'default_reasoning' => 'low', 'providers' => ['openai-codex' => ['type' => 'chatgpt', 'enabled' => true, 'compatibility' => ['thinking_format' => 'chatgpt', 'supports_reasoning_configuration_updates' => true], 'models' => ['gpt-6-sol' => ['reasoning' => true, 'tool_calling' => true, 'thinking_level_map' => ['low' => 'low', 'high' => 'high', 'max' => 'max'], 'compatibility' => ['supports_reasoning_configuration_updates' => true]], 'ordinary' => ['reasoning' => true, 'thinking_level_map' => ['low' => 'low', 'high' => 'high']]]]]]]);
        $this->assertNotNull($ai);
        $this->config = new AppConfig(new TuiConfig('default'), new LoggingConfig(), ai: $ai, catalog: new HatfieldModelCatalog($ai), cwd: $this->directory);
        $em = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $entity = new HatfieldSession();
        $entity->cwd = $this->directory;
        $entity->model = 'openai-codex/gpt-6-sol';
        $entity->reasoning = 'low';
        $em->persist($entity);
        $em->flush();
        $this->sessionId = (string) $entity->id;
        $this->sessions = new HatfieldSessionStore($this->config, $em, new EventDispatcher());
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testSwitchesResumeSummaryForkAndHistoryRewriteHaveIndependentEpochs(): void
    {
        $messages = [self::user('first')];
        $this->invoke($messages, 'low');
        $this->assertSame('low', $this->bodies[0]['reasoning']['effort']);
        $this->assertSame([], self::controls($this->bodies[0]));
        $messages[] = self::user('second');
        $this->invoke($messages, 'high');
        $this->assertSame('low', $this->bodies[1]['reasoning']['effort']);
        $this->assertSame(['high'], self::controls($this->bodies[1]));
        $this->assertSame('configuration_update', $this->bodies[1]['input'][1]['type']);
        $this->assertSame('second', $this->bodies[1]['input'][2]['content']);
        $this->invoke($messages, 'high');
        $this->assertSame($this->bodies[1], $this->bodies[2]);
        $messages[] = self::user('third');
        $this->invoke($messages, 'low');
        $this->assertSame(['high', 'low'], self::controls($this->bodies[3]));
        $this->assertSame('low', $this->bodies[3]['reasoning']['effort']);
        $this->assertSame($this->bodies[0]['prompt_cache_key'], $this->bodies[3]['prompt_cache_key']);
        // Drop the ORM identity map and construct a new store/adapter, as a resumed worker does.
        $em = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $em->clear();
        $this->sessions = new HatfieldSessionStore($this->config, $em, new EventDispatcher());
        $this->invoke($messages, 'low');
        $this->assertSame($this->bodies[3], $this->bodies[4]);
        $before = $em->find(HatfieldSession::class, (int) $this->sessionId)->reasoningBaseline;
        $this->invoke($messages, 'high', explicit: true);
        $this->assertSame([], self::controls($this->bodies[5]));
        $this->assertSame('high', $this->bodies[5]['reasoning']['effort']);
        $this->assertSame($before, $em->find(HatfieldSession::class, (int) $this->sessionId)->reasoningBaseline);
        $childId = Uuid::v7()->toRfc4122();
        $this->invoke($messages, 'high', explicit: true, runId: $childId);
        $this->assertSame([], self::controls($this->bodies[6]));
        $this->assertSame($childId, $this->bodies[6]['prompt_cache_key']);
        $this->assertNotSame($this->bodies[0]['prompt_cache_key'], $this->bodies[6]['prompt_cache_key']);
        $this->assertSame($before, $em->find(HatfieldSession::class, (int) $this->sessionId)->reasoningBaseline);
        // Discard both switch anchors: the retained request claims a fresh selected-effort baseline.
        $this->invoke([self::user('first')], 'high');
        $this->assertSame('high', $this->bodies[7]['reasoning']['effort']);
        $this->assertSame([], self::controls($this->bodies[7]));
        // Accepted compaction replaces the root; no old transition survives the summary.
        $this->invoke([self::user('compacted summary')], 'low');
        $this->assertSame('low', $this->bodies[8]['reasoning']['effort']);
        $this->assertSame([], self::controls($this->bodies[8]));
        $this->sessions->updateMetadata($this->sessionId, ['model' => 'openai-codex/ordinary']);
        $this->invoke([self::user('ordinary')], 'high');
        $this->assertSame('high', $this->bodies[9]['reasoning']['effort']);
        $this->assertSame([], self::controls($this->bodies[9]));
    }

    public function testMaxThenHighSurvivesColdCanonicalReplayWithDistinctAnchors(): void
    {
        $store = $this->startCanonicalReasoningEpoch('max');
        \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::append($store, \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($this->sessionId, 3, 'turn_advanced', ['turn_no' => 3, 'step_id' => 'third']));
        \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::append($store, \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($this->sessionId, 3, 'agent_command_applied', ['kind' => 'follow_up', 'idempotency_key' => 'third', 'message' => self::user('third')->toArray()]));
        \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::append($store, \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($this->sessionId, 3, 'history_position_set', ['position_turn_no' => 3]));
        $this->invoke($this->coldReplay($store)->messages, 'high');
        $this->assertSame('low', $this->bodies[2]['reasoning']['effort']);
        $this->assertSame(['max', 'high'], self::controls($this->bodies[2]));
        $this->assertSame('second', $this->bodies[2]['input'][2]['content']);
        $this->assertSame('third', $this->bodies[2]['input'][4]['content']);
        $this->assertSame($this->bodies[0]['prompt_cache_key'], $this->bodies[2]['prompt_cache_key']);
        $this->invoke($this->coldReplay($store)->messages, 'high');
        $this->assertSame($this->bodies[2], $this->bodies[3]);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidControlProvider')]
    public function testInvalidControlsNeverMutateDurableLedger(bool $historicalInvalid): void
    {
        $model = 'openai-codex/gpt-6-sol';
        $this->sessions->claimReasoningBaseline($this->sessionId, $model, 'low', ['a', 'b']);
        if ($historicalInvalid) {
            $this->sessions->rememberReasoningTransition($this->sessionId, $model, 'a', 'unsupported');
        }
        $em = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $before = $em->find(HatfieldSession::class, (int) $this->sessionId)->reasoningBaseline;
        $first = new \Symfony\AI\Platform\Message\UserMessage(new \Symfony\AI\Platform\Message\Content\Text('first'));
        $first->getMetadata()->add(\Ineersa\CodingAgent\Agent\Execution\ChatGPTReasoningTransitionMetadata::MESSAGE_KEY, 'a');
        $second = new \Symfony\AI\Platform\Message\UserMessage(new \Symfony\AI\Platform\Message\Content\Text('second'));
        $second->getMetadata()->add(\Ineersa\CodingAgent\Agent\Execution\ChatGPTReasoningTransitionMetadata::MESSAGE_KEY, 'b');
        try {
            (new ChatGPTReasoningTransitionRequestHook($this->sessions))->beforeProviderRequest($model, ['message_bag' => new \Symfony\AI\Platform\Message\MessageBag($first, $second)], [\Ineersa\CodingAgent\Agent\Execution\ChatGPTReasoningTransitionMetadata::ENABLED => true, \Ineersa\CodingAgent\Agent\Execution\ChatGPTReasoningTransitionMetadata::UPDATE => $historicalInvalid ? 'high' : 'unsupported', 'hatfield_run_id' => $this->sessionId, 'hatfield_model_ref' => $model]);
            $this->fail('An invalid control must be rejected before constructing or persisting the request.');
        } catch (\Symfony\AI\Platform\Exception\InvalidArgumentException $error) {
            $this->assertSame('ChatGPT reasoning configuration has an invalid effort.', $error->getMessage());
        }
        $em->clear();
        $after = $em->find(HatfieldSession::class, (int) $this->sessionId)->reasoningBaseline;
        $this->assertSame($before, $after);
    }

    public static function invalidControlProvider(): array
    {
        return ['invalid candidate' => [false], 'invalid history with valid candidate' => [true]];
    }

    public function testAcceptedCompactionStartsNewEpochButFailedAndRejectedResultsKeepControls(): void
    {
        $store = $this->startCanonicalReasoningEpoch();
        $messages = $this->coldReplay($store)->messages;
        $baseline = static::getContainer()->get('doctrine.orm.default_entity_manager')->find(HatfieldSession::class, (int) $this->sessionId)->reasoningBaseline;
        $state = new \Ineersa\AgentCore\Domain\Run\RunState(runId: $this->sessionId, status: \Ineersa\AgentCore\Domain\Run\RunStatus::Compacting, turnNo: 2, lastSeq: $store->latestSequenceFor($this->sessionId), messages: $messages, activeStepId: 'compact', currentOperation: new \Ineersa\AgentCore\Domain\Run\CurrentOperationDTO(2, 'compact', 1, 'compact-key'));
        $handler = static::getContainer()->get(\Ineersa\CodingAgent\Application\Pipeline\CompactionStepResultHandler::class);
        $resultMessage = fn (?string $summary, int $attempt = 1, string $step = 'compact'): \Ineersa\AgentCore\Domain\Message\CompactionStepResult => new \Ineersa\AgentCore\Domain\Message\CompactionStepResult(runId: $this->sessionId, turnNo: 2, stepId: $step, attempt: $attempt, idempotencyKey: $step.'-key', summaryText: $summary, error: null, retainedTailMessages: [], messagesCompacted: 2, messagesRetained: 0, firstRetainedIndex: 2, tokenEstimateBefore: 50000, trigger: 'manual');
        $rejected = $handler->handle($resultMessage('ignored', 2), $state);
        $this->assertSame([], $rejected->events);
        $this->assertNull($rejected->nextState);
        $failed = $handler->handle($resultMessage(''), $state);
        $this->assertSame('context_compaction_failed', $failed->events[0]->type);
        foreach ($failed->events as $event) {
            \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::append($store, $event);
        }
        $failedState = $this->coldReplay($store);
        $this->invoke($failedState->messages, 'high');
        $this->assertSame('low', $this->bodies[2]['reasoning']['effort']);
        $this->assertSame(['high'], self::controls($this->bodies[2]));
        $this->assertSame($baseline, static::getContainer()->get('doctrine.orm.default_entity_manager')->find(HatfieldSession::class, (int) $this->sessionId)->reasoningBaseline);
        // Start a distinct operation after the failed attempt has been committed.
        $retry = $failedState->with(['status' => \Ineersa\AgentCore\Domain\Run\RunStatus::Compacting, 'activeStepId' => 'compact-accepted', 'currentOperation' => new \Ineersa\AgentCore\Domain\Run\CurrentOperationDTO(2, 'compact-accepted', 1, 'compact-accepted-key')]);
        $accepted = $handler->handle($resultMessage('Summary replacing both anchors.', step: 'compact-accepted'), $retry);
        $this->assertNotNull($accepted->nextState);
        $this->assertSame('context_compacted', $accepted->events[0]->type);
        foreach ($accepted->events as $event) {
            \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::append($store, $event);
        }
        $compacted = $this->coldReplay($store);
        $this->assertCount(1, $compacted->messages);
        $this->invoke($compacted->messages, 'high');
        $this->assertSame('high', $this->bodies[3]['reasoning']['effort']);
        $this->assertSame([], self::controls($this->bodies[3]));
        $this->assertSame($this->bodies[0]['prompt_cache_key'], $this->bodies[3]['prompt_cache_key']);
    }

    public function testCommittedHistoryTailDiscardRemovesSwitchAnchorWithoutChangingCacheIdentity(): void
    {
        $store = $this->startCanonicalReasoningEpoch();
        $service = new \Ineersa\CodingAgent\Session\History\HistoryTailDiscardService($store, new \Ineersa\CodingAgent\Session\History\HistoryProjector(), new NullLogger());
        $tip = $this->coldReplay($store);
        $this->assertNull($service->prepareForwardTailDiscard($this->sessionId, $tip));
        \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::append($store, \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($this->sessionId, 1, 'history_position_set', ['position_turn_no' => 1, 'reason' => 'history_select']));
        $selected = $this->coldReplay($store, 1);
        $discard = $service->prepareForwardTailDiscard($this->sessionId, $selected);
        $this->assertNotNull($discard);
        $this->assertSame('history_tail_discarded', $discard->type);
        $this->assertSame(1, $discard->payload['after_turn_no']);
        \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::append($store, $discard);
        $service->afterDiscardCommitted($this->sessionId);
        $retained = $this->coldReplay($store);
        $this->assertCount(1, $retained->messages);
        $this->assertSame('first', $retained->messages[0]->content[0]['text']);
        $this->invoke($retained->messages, 'high');
        $this->assertSame('high', $this->bodies[2]['reasoning']['effort']);
        $this->assertSame([], self::controls($this->bodies[2]));
        $this->assertSame($this->bodies[0]['prompt_cache_key'], $this->bodies[2]['prompt_cache_key']);
        // A normal resume after the accepted mutation retains the new epoch.
        $this->invoke($this->coldReplay($store)->messages, 'high');
        $this->assertSame($this->bodies[2], $this->bodies[3]);
    }

    private function startCanonicalReasoningEpoch(string $effort = 'high'): \Ineersa\CodingAgent\Session\SessionRunEventStore
    {
        $store = new \Ineersa\CodingAgent\Session\SessionRunEventStore($this->sessions, new \Ineersa\AgentCore\Schema\EventPayloadNormalizer(), new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore()), new NullLogger(), new \Ineersa\CodingAgent\Session\FileRunSequenceAllocator());
        $append = fn (int $turn, string $type, array $payload) => \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::append($store, \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($this->sessionId, $turn, $type, $payload));
        $append(0, 'run_started', ['payload' => ['messages' => [self::user('first')->toArray()]]]);
        $append(1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'first']);
        $append(1, 'history_position_set', ['position_turn_no' => 1]);
        $this->invoke($this->coldReplay($store)->messages, 'low');
        $append(2, 'turn_advanced', ['turn_no' => 2, 'step_id' => 'second']);
        $append(2, 'agent_command_applied', ['kind' => 'follow_up', 'idempotency_key' => 'follow-up', 'message' => self::user('second')->toArray()]);
        $append(2, 'history_position_set', ['position_turn_no' => 2]);
        $this->invoke($this->coldReplay($store)->messages, $effort);
        $this->assertSame('low', $this->bodies[1]['reasoning']['effort']);
        $this->assertSame([$effort], self::controls($this->bodies[1]));

        return $store;
    }

    private function coldReplay(\Ineersa\AgentCore\Contract\EventStoreInterface $store, ?int $position = null): \Ineersa\AgentCore\Domain\Run\RunState
    {
        $em = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $em->clear();
        $this->sessions = new HatfieldSessionStore($this->config, $em, new EventDispatcher());
        $replay = new \Ineersa\CodingAgent\Session\Replay\SessionRunStateReplayService($store, new NullLogger(), static::getContainer()->get(\Ineersa\AgentCore\Application\Replay\RunStateReducer::class), new \Ineersa\AgentCore\Application\Replay\ReplayEventPreparer(), new \Ineersa\CodingAgent\Session\History\HistoryReplayFilter(new \Ineersa\CodingAgent\Session\History\HistoryProjector()));
        $state = \Ineersa\AgentCore\Domain\Run\RunState::queued($this->sessionId);
        $rebuilt = null === $position ? $replay->rebuildIfStale($state, $this->sessionId) : $replay->rebuildAtPosition($state, $this->sessionId, $position);
        $this->assertNotNull($rebuilt->rebuiltState);

        return $rebuilt->rebuiltState;
    }

    /** @param list<AgentMessage> $messages */
    private function invoke(array $messages, string $effort, bool $explicit = false, ?string $runId = null): void
    {
        if (!$explicit) {
            $this->sessions->updateMetadata($this->sessionId, ['reasoning' => $effort]);
        }
        $paths = new SettingsPathResolver($this->directory, $this->directory.'/home');
        $writer = new SettingsOverrideWriter($paths, PropertyAccess::createPropertyAccessor(), new Filesystem());
        $selection = new ModelSelectionService($this->config, new ModelResolver($this->config, $this->sessions, new NullLogger()), $writer, $this->sessions);
        $catalog = $this->config->catalog;
        $this->assertNotNull($catalog);
        $resolver = new SessionAwareModelResolver($selection, $catalog, $this->sessions, static::getContainer()->get(DeferredSubagentChildRepository::class));
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->bodies[] = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);

            return new MockResponse("data: {\"type\":\"response.output_text.delta\",\"delta\":\"done\"}\n\ndata: {\"type\":\"response.completed\",\"response\":{\"status\":\"completed\",\"output\":[]}}\n\n", ['response_headers' => ['content-type: text/event-stream']]);
        });
        $record = ChatGPTAuthFixture::record();
        $storage = $this->createStub(AuthStorageInterface::class);
        $storage->method('update')->willReturnCallback(static fn (callable $update) => $update($record));
        $builder = new ChatGPTSymfonyAiProviderBuilder(ChatGPTAuthFixture::service($storage, $http), new EventDispatcher());
        $provider = $catalog->getProvider('openai-codex');
        $this->assertNotNull($provider);
        $adapter = new LlmPlatformAdapter(new NullRunOperationalStatusReader(), new AgentMessageConverter(), new DynamicToolDescriptionProcessor(), new Platform([$builder->build($provider, $http)]), [new ChatGPTReasoningTransitionTransformHook($selection, $catalog, $this->sessions)], [], null, null, new NullLogger(), AttributeSerializerValidatorTestFactory::denormalizer(), $resolver, new ProviderRequestPreparer([new ChatGPTReasoningTransitionRequestHook($this->sessions)], new ProviderCompatibilityRequestShaper([new ReasoningOptionsFeatureShaper()])));
        $result = $adapter->invoke(new ModelInvocationRequest($explicit ? 'openai-codex/gpt-6-sol' : '', new ModelInvocationInput(runId: $runId ?? $this->sessionId, messages: $messages), new ModelInvocationOptions(extraOptions: $explicit ? ['thinking_level' => $effort] : [])));
        $this->assertNull($result->error);
    }

    private static function user(string $text): AgentMessage
    {
        return new AgentMessage('user', [['type' => 'text', 'text' => $text]]);
    }

    /** @param array<string, mixed> $body */
    private static function controls(array $body): array
    {
        return array_values(array_map(static fn (array $item): string => $item['reasoning']['effort'], array_filter($body['input'], static fn (array $item): bool => 'configuration_update' === ($item['type'] ?? null))));
    }
}
