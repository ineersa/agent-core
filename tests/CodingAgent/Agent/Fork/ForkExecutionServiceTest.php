<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Fork;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Contract\Compaction\MessageSnapshotCompactionResult;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionOutcome;
use Ineersa\AgentCore\Domain\Tool\ToolLaunchContextDTO;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactStatusEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeExecutionService;
use Ineersa\CodingAgent\Agent\Execution\AgentResumeTaskDTO;
use Ineersa\CodingAgent\Agent\Execution\RunStartedMetadataReader;
use Ineersa\CodingAgent\Agent\Execution\SessionAwareModelResolver;
use Ineersa\CodingAgent\Agent\Fork\ForkExecutionService;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ProjectedSymfonyModelCatalog;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\ProviderInterface;

#[Group('db')]
final class ForkExecutionServiceTest extends PerMethodIsolatedKernelTestCase
{
    public function testNativeSnapshotSurvivesPersistedForkAndColdChildReplayWithoutParentControls(): void
    {
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.default_entity_manager');
        $entity = new \Ineersa\CodingAgent\Entity\HatfieldSession();
        $entity->cwd = (string) getcwd();
        $entity->model = 'openai-codex/gpt-5.5';
        $entity->reasoning = 'low';
        $em->persist($entity);
        $em->flush();
        $parent = (string) $entity->id;
        $parentKey = $entity->providerCacheKey;
        $this->appendCanonicalParentRun($parent, $entity->model, 2);
        $container->get(RunOperationalProjectionRepository::class)->replace(new RunState($parent, RunStatus::Running));
        $frames = [];
        $http = new \Symfony\Component\HttpClient\MockHttpClient(static function (string $method, string $url, array $options) use (&$frames): \Symfony\Component\HttpClient\Response\MockResponse {
            $frames[] = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);

            return \Ineersa\CodingAgent\Tests\Support\ChatGPTNativeReplayFixture::response(1 === \count($frames));
        });
        $adapter = $this->forkProviderAdapter($this->httpProvider($http));
        $messages = [new AgentMessage('user', [['type' => 'text', 'text' => 'Read both fixtures']])];
        $native = $adapter->invoke(new ModelInvocationRequest('', new ModelInvocationInput(runId: $parent, messages: $messages)));
        $this->assertNull($native->error);
        $this->assertNotNull($native->assistantMessage);
        $messages[] = (new \Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer())->assistantMessage($native->assistantMessage, 'openai-codex/gpt-5.5');
        foreach (['call_one', 'call_two'] as $id) {
            $messages[] = new AgentMessage('tool', [['type' => 'text', 'text' => 'contents']], toolCallId: $id.'|fc_'.$id, toolName: 'read_file');
        }
        $messages[] = new AgentMessage('user', [['type' => 'text', 'text' => 'Upgrade parent reasoning']]);
        $container->get(HatfieldSessionStore::class)->updateMetadata($parent, ['reasoning' => 'high']);
        $parentResult = $adapter->invoke(new ModelInvocationRequest('', new ModelInvocationInput(runId: $parent, messages: $messages)));
        $this->assertNull($parentResult->error);
        $this->assertSame('low', $frames[1]['reasoning']['effort']);
        $this->assertCount(1, array_filter($frames[1]['input'], static fn (array $item): bool => 'configuration_update' === ($item['type'] ?? null)));
        $baseline = $entity->reasoningBaseline;
        // The snapshot may already carry transform metadata from its owner. A child must strip it.
        $anchor = array_key_last($messages);
        $messages[$anchor] = new AgentMessage('user', $messages[$anchor]->content, metadata: [\Ineersa\CodingAgent\Agent\Execution\ChatGPTReasoningTransitionMetadata::KEY => 'high']);
        $snapshot = new ToolLaunchContextDTO(kind: ToolLaunchContextDTO::KIND_FORK, producingRunId: $parent, producingTurnNo: 2, producingModel: 'openai-codex/gpt-5.5', forkMessages: $messages);
        $started = null;
        $runner = $this->createMock(AgentRunnerInterface::class);
        $runner->expects($this->once())->method('start')->willReturnCallback(static function (StartRunInput $input) use (&$started): string {
            $started = $input;

            return $input->runId;
        });
        $container->set(AgentRunnerInterface::class, $runner);
        $compaction = $this->createStub(CompactionServiceInterface::class);
        $compaction->method('compactMessages')->willReturnCallback(static fn (string $runId, int $turnNo, array $history): MessageSnapshotCompactionResult => MessageSnapshotCompactionResult::structuralNoOp($history));
        $container->set(CompactionServiceInterface::class, $compaction);
        $fork = $container->get(ForkExecutionService::class);
        $launch = $this->withToolContext($parent, 'native-fork', static fn () => $fork->execute($parent, 'Continue independently', $snapshot, modelOverride: 'openai-codex/gpt-5.5', reasoningOverride: 'low'), launchContext: $snapshot);
        $this->assertNotNull($started);
        $child = $container->get(DeferredSubagentChildRepository::class)->findOrderedByBatchLifecycleId($launch->deferredId)[0];
        $childId = $child->childRunId;
        $key = $container->get(DeferredSubagentChildRepository::class)->findProviderCacheKey($childId);
        $this->assertNotNull($key);
        $store = $container->get(AgentChildRunEventStoreFactory::class)->create($parent, $childId, $child->artifactId);
        $metadata = $container->get(\Symfony\Component\Serializer\SerializerInterface::class)->normalize($started->metadata);
        PreparedEventStoreSeeder::append($store, RunEvent::forAppend($childId, 0, 'run_started', ['payload' => ['metadata' => $metadata, 'messages' => array_map(static fn (AgentMessage $message): array => $message->toArray(), $started->messages)]]));
        $em->clear();
        $cold = $container->get(AgentChildRunEventStoreFactory::class)->create($parent, $childId, $child->artifactId);
        $coordinator = $container->get(\Ineersa\CodingAgent\Session\Replay\SessionReplayCoordinator::class);
        $replay = new \Ineersa\CodingAgent\Session\Replay\SessionRunStateReplayService($cold, $coordinator);
        $state = $replay->rebuildIfStale(RunState::queued($childId), $childId)->rebuiltState;
        $this->assertNotNull($state);
        foreach ([0, 1] as $restart) {
            $em->clear();
            $result = $this->forkProviderAdapter($this->httpProvider($http))->invoke(new ModelInvocationRequest('', new ModelInvocationInput(runId: $childId, messages: $state->messages)));
            $this->assertNull($result->error);
            $body = $frames[2 + $restart];
            $this->assertSame(\Ineersa\CodingAgent\Tests\Support\ChatGPTNativeReplayFixture::items(), \Ineersa\CodingAgent\Tests\Support\ChatGPTNativeReplayFixture::replayedItems($body));
            $this->assertSame($key, $body['prompt_cache_key']);
            $this->assertNotSame($parentKey, $body['prompt_cache_key']);
            $this->assertSame('low', $body['reasoning']['effort']);
            $this->assertSame([], array_values(array_filter($body['input'], static fn (array $item): bool => 'configuration_update' === ($item['type'] ?? null))));
            $this->assertSame($baseline, $em->find(\Ineersa\CodingAgent\Entity\HatfieldSession::class, (int) $parent)->reasoningBaseline);
        }
        $parentAgain = $this->forkProviderAdapter($this->httpProvider($http))->invoke(new ModelInvocationRequest('', new ModelInvocationInput(runId: $parent, messages: $messages)));
        $this->assertNull($parentAgain->error);
        $this->assertSame($parentKey, $frames[4]['prompt_cache_key']);
        $this->assertSame('low', $frames[4]['reasoning']['effort']);
        $this->assertSame(['high'], array_values(array_map(static fn (array $item): string => $item['reasoning']['effort'], array_filter($frames[4]['input'], static fn (array $item): bool => 'configuration_update' === ($item['type'] ?? null)))));
    }

    public function testDeferredForkKeepsProviderKeyThroughRequestsWorkerRecreationAndResume(): void
    {
        $container = self::getContainer();
        $parent = 'parent-provider-cache';
        $started = [];
        $runner = $this->createMock(AgentRunnerInterface::class);
        $runner->expects($this->exactly(2))->method('start')->willReturnCallback(
            static function (StartRunInput $input) use (&$started): string {
                $started[] = $input;

                return $input->runId;
            },
        );
        $runner->expects($this->once())->method('followUp');
        $container->set(AgentRunnerInterface::class, $runner);
        $compaction = $this->createStub(CompactionServiceInterface::class);
        $compaction->method('compactMessages')->willReturnCallback(
            static fn (string $runId, int $turnNo, array $messages): MessageSnapshotCompactionResult => MessageSnapshotCompactionResult::structuralNoOp($messages),
        );
        $container->set(CompactionServiceInterface::class, $compaction);
        $this->appendCanonicalParentRun($parent, 'deepseek/deepseek-v4-flash', 2);
        $container->get(RunOperationalProjectionRepository::class)->replace(new RunState($parent, RunStatus::Running));
        $fork = $container->get(ForkExecutionService::class);
        $launch = $this->withToolContext($parent, 'fork-cache-one', fn () => $fork->execute(
            $parent, 'first fork', $this->forkLaunchContext($parent), modelOverride: 'openai-codex/gpt-5.5',
        ));
        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $launch);
        $child = $container->get(DeferredSubagentChildRepository::class)->findOrderedByBatchLifecycleId($launch->deferredId)[0];
        $this->assertSame($started[0]->runId, $child->childRunId);
        $store = $container->get(AgentChildRunEventStoreFactory::class)->create($parent, $child->childRunId, $child->artifactId);
        $metadata = $container->get(\Symfony\Component\Serializer\SerializerInterface::class)->normalize($started[0]->metadata);
        PreparedEventStoreSeeder::append($store, RunEvent::forAppend($child->childRunId, 1, 'run_started', ['payload' => ['metadata' => $metadata]]));

        $frames = [];
        $httpClient = new \Symfony\Component\HttpClient\MockHttpClient(static function (string $method, string $url, array $options) use (&$frames): \Symfony\Component\HttpClient\Response\MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('https://api.openai.com/v1/responses', $url);
            $frames[] = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);

            return new \Symfony\Component\HttpClient\Response\MockResponse('data: {"type":"response.output_text.delta","delta":"ok"}'."\n\n".'data: {"type":"response.completed","response":{"id":"resp_test","status":"completed","output":[]}}'."\n\n", ['response_headers' => ['content-type: text/event-stream']]);
        });
        try {
            $adapter = $this->forkProviderAdapter($this->httpProvider($httpClient));
            $messages = [new AgentMessage('user', [['type' => 'text', 'text' => 'first']])];
            $first = $adapter->invoke(new ModelInvocationRequest('fallback/unused', new ModelInvocationInput(runId: $child->childRunId, messages: $messages)));
            $this->assertNotNull($first->assistantMessage);
            $messages[] = new AgentMessage('assistant', [['type' => 'text', 'text' => $first->assistantMessage->asText()]]);
            $messages[] = new AgentMessage('user', [['type' => 'text', 'text' => 'second']]);
            $second = $adapter->invoke(new ModelInvocationRequest('fallback/unused', new ModelInvocationInput(runId: $child->childRunId, messages: $messages)));
            $this->assertNotNull($second->assistantMessage);
            $this->assertSame($container->get(DeferredSubagentChildRepository::class)->findProviderCacheKey($child->childRunId), $frames[0]['prompt_cache_key']);
            $this->assertSame($frames[0]['prompt_cache_key'], $frames[1]['prompt_cache_key']);
            $this->assertArrayNotHasKey('previous_response_id', $frames[1]);
            $this->assertCount(3, $frames[1]['input']);

            // Worker recreation must retain the durable key and send full history.
            $container->get('doctrine.orm.default_entity_manager')->clear();
            $adapter = $this->forkProviderAdapter($this->httpProvider($httpClient));
            $messages[] = new AgentMessage('assistant', [['type' => 'text', 'text' => $second->assistantMessage->asText()]]);
            $messages[] = new AgentMessage('user', [['type' => 'text', 'text' => 'recreated worker']]);
            $third = $adapter->invoke(new ModelInvocationRequest('fallback/unused', new ModelInvocationInput(runId: $child->childRunId, messages: $messages)));
            $this->assertNotNull($third->assistantMessage);
            $this->assertSame($frames[0]['prompt_cache_key'], $frames[2]['prompt_cache_key']);
            $this->assertArrayNotHasKey('previous_response_id', $frames[2]);

            $terminal = PreparedEventStoreSeeder::append($store, RunEvent::forAppend($child->childRunId, 1, 'agent_end', ['reason' => 'completed']));
            $container->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Recovery\DeferredSubagentBatchRecoveryService::class)->recover($launch->deferredId);
            $container->get(RunOperationalProjectionRepository::class)->replace(new RunState(
                $child->childRunId, RunStatus::Completed, parentRunId: $parent, lastSeq: $terminal->seq,
            ));
            $container->get(AgentArtifactRegistry::class)->update($parent, $child->artifactId, status: AgentArtifactStatusEnum::Completed);
            $resume = $container->get(AgentResumeExecutionService::class);
            $this->withToolContext($parent, 'cache-resume', static fn () => $resume->resume($parent,
                [new AgentResumeTaskDTO(artifact_id: $child->artifactId, task: 'resume')],
                \Ineersa\CodingAgent\Agent\Execution\ChildRun\Contract\ChildRunBatchExecutionModeEnum::Single,
            ), toolName: 'agent_resume');
            $messages[] = new AgentMessage('assistant', [['type' => 'text', 'text' => $third->assistantMessage->asText()]]);
            $messages[] = new AgentMessage('user', [['type' => 'text', 'text' => 'resume']]);
            $resumed = $adapter->invoke(new ModelInvocationRequest('fallback/unused', new ModelInvocationInput(runId: $child->childRunId, messages: $messages)));
            $this->assertNotNull($resumed->assistantMessage);
            $this->assertSame($frames[0]['prompt_cache_key'], $frames[3]['prompt_cache_key']);

            $other = $this->withToolContext($parent, 'fork-cache-two', fn () => $fork->execute(
                $parent, 'different fork', $this->forkLaunchContext($parent), modelOverride: 'openai-codex/gpt-5.5',
            ));
            $otherChild = $container->get(DeferredSubagentChildRepository::class)->findOrderedByBatchLifecycleId($other->deferredId)[0];
            $result = $adapter->invoke(new ModelInvocationRequest('fallback/unused', new ModelInvocationInput(
                runId: $otherChild->childRunId, messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'different fork']])],
            )));
            $this->assertNotNull($result->assistantMessage);
            $this->assertCount(5, $frames);
            $this->assertNotSame($frames[0]['prompt_cache_key'], $frames[4]['prompt_cache_key']);
        } finally {
            $httpClient->reset();
        }
    }

    public function testForkExecutionEntersDeferredSingleChildLifecycleOnce(): void
    {
        $parentRunId = 'parent-fork-int-1';
        $toolCallId = 'call-fork-int-1';

        $agentRunner = $this->createMock(AgentRunnerInterface::class);
        $agentRunner->expects($this->once())->method('start')->willReturnCallback(
            static fn (StartRunInput $input): string => $input->runId,
        );

        $compaction = $this->createMock(CompactionServiceInterface::class);
        $compaction->expects($this->atLeastOnce())
            ->method('compactMessages')
            ->willReturnCallback(
                function (
                    string $runId,
                    int $turnNo,
                    array $messages,
                    string $trigger = 'manual',
                    ?string $customInstructions = null,
                    ?string $activeModel = null,
                ) use ($parentRunId): MessageSnapshotCompactionResult {
                    $this->assertSame($parentRunId, $runId);
                    $this->assertSame(2, $turnNo);
                    $this->assertSame('fork', $trigger);
                    $this->assertSame('deepseek/deepseek-v4-flash', $activeModel);

                    return MessageSnapshotCompactionResult::structuralNoOp($messages);
                },
            );

        $container = self::getContainer();
        $container->set(AgentRunnerInterface::class, $agentRunner);
        $container->set(CompactionServiceInterface::class, $compaction);

        $this->appendCanonicalParentRun($parentRunId, 'deepseek/deepseek-v4-flash', 2);
        $container->get(RunOperationalProjectionRepository::class)->replace(new RunState($parentRunId, RunStatus::Running));

        $forkExecution = $container->get(ForkExecutionService::class);

        $launchContext = $this->forkLaunchContext($parentRunId);
        $outcome = $this->withToolContext($parentRunId, $toolCallId, static fn () => $forkExecution->execute(
            $parentRunId,
            'Delegated integration task',
            $launchContext,
        ));

        $this->assertInstanceOf(DeferredToolCompletionOutcome::class, $outcome);

        $retry = $this->withToolContext($parentRunId, $toolCallId, static fn () => $forkExecution->execute(
            $parentRunId,
            'Delegated integration task',
            $launchContext,
        ));
        $this->assertSame($outcome->deferredId, $retry->deferredId);
    }

    public function testForkFailsClosedWhenParentRunStateMissing(): void
    {
        $parentRunId = 'parent-fork-missing-state';

        $compaction = $this->createMock(CompactionServiceInterface::class);
        $compaction->expects($this->never())->method('compactMessages');
        self::getContainer()->set(CompactionServiceInterface::class, $compaction);

        $forkExecution = self::getContainer()->get(ForkExecutionService::class);

        try {
            $missingLaunchContext = $this->forkLaunchContext($parentRunId);
            $this->withToolContext($parentRunId, 'call-missing-state', static fn () => $forkExecution->execute(
                $parentRunId,
                'Delegated integration task',
                $missingLaunchContext,
            ));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            // Missing operational relationship fails closed before canonical replay.
            $this->assertStringContainsString('Operational relationship for run', $e->getMessage());
            $this->assertStringContainsString($parentRunId, $e->getMessage());
            $this->assertFalse($e->retryable());
        }
    }

    public function testNestedForkRejectedBeforeReservation(): void
    {
        $childRunId = 'child-fork-nested-1';
        $eventStore = self::getContainer()->get(EventStoreInterface::class);
        PreparedEventStoreSeeder::append($eventStore, new RunEvent(
            runId: $childRunId,
            seq: 1,
            turnNo: 1,
            type: \Ineersa\AgentCore\Domain\Event\RunEventTypeEnum::RunStarted->value,
            payload: [
                'payload' => [
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'parent_run_id' => 'parent-1',
                            'agent_name' => 'fork',
                            'artifact_id' => 'agent_fork1',
                        ],
                        'model' => 'deepseek/deepseek-v4-flash',
                        'reasoning' => 'medium',
                        'tools_scope' => ['allowed_tools' => []],
                    ],
                ],
            ],
        ));

        // Stub compaction so ForkExecutionService can resolve without PlatformInterface providers.
        $compaction = $this->createStub(CompactionServiceInterface::class);
        $compaction->method('compactMessages')->willThrowException(new \LogicException('compactMessages must not run for nested fork'));
        self::getContainer()->set(CompactionServiceInterface::class, $compaction);

        self::getContainer()->get(RunOperationalProjectionRepository::class)->replace(
            new RunState($childRunId, RunStatus::Running, parentRunId: 'parent-1'),
        );

        $forkExecution = self::getContainer()->get(ForkExecutionService::class);

        try {
            $nestedLaunchContext = $this->forkLaunchContext($childRunId);
            $this->withToolContext($childRunId, 'call-nested', static fn () => $forkExecution->execute(
                $childRunId,
                'nested',
                $nestedLaunchContext,
            ));
            $this->fail('Expected ToolCallException');
        } catch (ToolCallException $e) {
            $this->assertStringContainsString('is an agent child; nested launches are not supported', $e->getMessage());
        }
    }

    private function httpProvider(\Symfony\Contracts\HttpClient\HttpClientInterface $client): ProviderInterface
    {
        $record = \Ineersa\CodingAgent\Tests\Support\ChatGPTAuthFixture::record();
        $storage = $this->createStub(\Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthStorageInterface::class);
        $storage->method('update')->willReturnCallback(static fn (callable $update) => $update($record));

        return \Symfony\AI\Platform\Bridge\OpenAIChatGPT\Factory::createProvider(
            \Ineersa\CodingAgent\Tests\Support\ChatGPTAuthFixture::service($storage, $client), $client,
            new ProjectedSymfonyModelCatalog(['gpt-5.5' => new AiModelDefinition(id: 'gpt-5.5', reasoning: true)], ResponsesModel::class, 'openai-codex'),
            name: 'openai-codex',
        );
    }

    private function forkProviderAdapter(ProviderInterface $provider): LlmPlatformAdapter
    {
        $container = self::getContainer();
        $catalog = new HatfieldModelCatalog(new AiConfig(defaultModel: 'openai-codex/gpt-5.5', providers: [
            'openai-codex' => new AiProviderConfig(id: 'openai-codex', type: 'chatgpt', enabled: true,
                compatibility: \Ineersa\CodingAgent\Config\Ai\AiCompatibility::fromArray(['thinking_format' => 'chatgpt', 'supports_reasoning_configuration_updates' => true]),
                models: ['gpt-5.5' => new AiModelDefinition(id: 'gpt-5.5', reasoning: true, thinkingLevelMap: ['low' => 'low', 'high' => 'high'], compatibility: \Ineersa\CodingAgent\Config\Ai\AiCompatibility::fromArray(['supports_reasoning_configuration_updates' => true]))]),
        ]));
        $config = new AppConfig(new TuiConfig(theme: 'default'), new LoggingConfig(), catalog: $catalog);
        $store = $container->get(HatfieldSessionStore::class);
        $selection = new ModelSelectionService($config, new \Ineersa\CodingAgent\Config\ModelResolver($config, $store, new NullLogger()), $container->get(SettingsOverrideWriter::class), $store);
        $metadataReader = new RunStartedMetadataReader($container->get(EventStoreInterface::class), AttributeSerializerValidatorTestFactory::denormalizer());
        $resolver = new SessionAwareModelResolver($selection, $catalog, $store,
            $container->get(DeferredSubagentChildRepository::class), $metadataReader);

        return new LlmPlatformAdapter(
            statusReader: new NullRunOperationalStatusReader(), messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(), platform: new Platform([$provider]),
            transformContextHooks: [new \Ineersa\CodingAgent\Agent\Execution\ChatGPTReasoningTransitionTransformHook($selection, $catalog, $store, childMetadataReader: $metadataReader)], convertToLlmHooks: [], streamObserver: null, costCalculator: null,
            logger: new NullLogger(), denormalizer: AttributeSerializerValidatorTestFactory::denormalizer(), modelResolver: $resolver,
            providerRequestPreparer: new \Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderRequestPreparer([new \Ineersa\CodingAgent\Agent\Execution\ChatGPTReasoningTransitionRequestHook($store)], new \Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderCompatibilityRequestShaper([new \Ineersa\AgentCore\Infrastructure\SymfonyAi\ReasoningOptionsFeatureShaper()])),
        );
    }

    private function appendCanonicalParentRun(string $runId, ?string $model, int $turnNo): void
    {
        $metadata = ['session' => ['kind' => 'parent']];
        if (null !== $model) {
            $metadata['model'] = $model;
        }
        $eventStore = self::getContainer()->get(EventStoreInterface::class);
        PreparedEventStoreSeeder::append($eventStore, new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: \Ineersa\AgentCore\Domain\Event\RunEventTypeEnum::RunStarted->value,
            payload: ['payload' => ['metadata' => $metadata, 'messages' => []]],
        ));
        PreparedEventStoreSeeder::append($eventStore, new RunEvent(
            runId: $runId,
            seq: 2,
            turnNo: $turnNo,
            type: \Ineersa\AgentCore\Domain\Event\RunEventTypeEnum::TurnAdvanced->value,
            payload: ['turn_no' => $turnNo, 'step_id' => 'parent-step'],
        ));
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    private function withToolContext(string $parentRunId, string $toolCallId, callable $callback, string $toolName = 'fork', ?ToolLaunchContextDTO $launchContext = null): mixed
    {
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $launchContext ??= $this->forkLaunchContext($parentRunId);
        $context = new ToolContext(
            runId: $parentRunId,
            turnNo: 2,
            toolCallId: $toolCallId,
            toolName: $toolName,
            cancellationToken: new NullCancellationToken(),
            timeoutSeconds: 120,
            orderIndex: 0,
            parentModel: $launchContext->producingModel,
            launchContext: $launchContext,
        );

        return $accessor->with($context, $callback);
    }

    private function forkLaunchContext(string $parentRunId): ToolLaunchContextDTO
    {
        return new ToolLaunchContextDTO(
            kind: ToolLaunchContextDTO::KIND_FORK,
            producingRunId: $parentRunId,
            producingTurnNo: 2,
            producingModel: 'deepseek/deepseek-v4-flash',
        );
    }
}
