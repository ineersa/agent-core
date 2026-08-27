<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Session;

use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\PlatformInvocationMetadata;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Diagnostics\PromptCacheDiagnosticsInvocationSubscriber;
use Ineersa\CodingAgent\Agent\Diagnostics\PromptCacheDiagnosticsStore;
use Ineersa\CodingAgent\Agent\Diagnostics\SessionPromptCacheInspectionService;
use Ineersa\CodingAgent\CLI\Session\SessionCacheInspectCommand;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\AI\Platform\Event\InvocationEvent;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ValidatorBuilder;

/**
 * Thesis A: opt-in dual-priority subscriber sees final shaped MessageBag/tools, writes parent/child
 * sidecars only when enabled, does not mutate InvocationEvent, and never serializes raw prompts/keys/secrets.
 * Thesis B: inspect joins diagnostics with usage, prevents multi-record double-count, reports
 * first prefix change, and gives one actionable warning for diagnostics-free historical usage.
 */
final class SessionCacheInspectCommandTest extends IsolatedKernelTestCase
{
    #[Test]
    public function subscriberDisabledDoesNotCreateParentOrChildSidecars(): void
    {
        $projectDir = getcwd();
        $this->assertNotFalse($projectDir);
        $sessionId = $this->seedSessionRow($projectDir);
        $sessionDir = $projectDir.'/.hatfield/sessions/'.$sessionId;
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0777, true);
        }

        $hatfieldSessionStore = $this->sessionStoreForCwd($projectDir);
        $registry = $this->artifactRegistry($hatfieldSessionStore);
        $childDirectory = new AgentChildRunDirectory($hatfieldSessionStore, $registry, new TestLogger());
        $diagStore = static::getContainer()->get(PromptCacheDiagnosticsStore::class);
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(static::getContainer()->get(PromptCacheDiagnosticsInvocationSubscriber::class));

        $this->dispatchInvocation($dispatcher, $sessionId, 1, 'parent-step', 'openai-codex/gpt-5.6', 'system', 'secret', 'read', null);
        $childRunId = '0194eeee-aaaa-7bbb-8ccc-dddddddddddd';
        $artifactId = 'disabled-child';
        $entry = $registry->create($sessionId, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $childDirectory->register($entry);
        $this->dispatchInvocation($dispatcher, $childRunId, 1, 'child-step', 'deepseek/deepseek-v4-flash', 'system', 'secret', 'read', null);

        $this->assertSame([], $diagStore->readForRun($sessionId));
        $this->assertSame([], $diagStore->readForRun($childRunId));
        $this->assertFileDoesNotExist($sessionDir.'/diagnostics/prompt-cache.jsonl');
        $this->assertFileDoesNotExist((new SessionAgentArtifactPathResolver($hatfieldSessionStore))->resolveArtifactDir($sessionId, $artifactId).'/diagnostics/prompt-cache.jsonl');
    }

    #[Test]
    public function subscriberEnabledWritesSidecarsAndInspectJoinsParentChildHistoryPrivacy(): void
    {
        $projectDir = getcwd();
        $this->assertNotFalse($projectDir);
        $sessionId = $this->seedSessionRow($projectDir, 'openai-codex/gpt-5.6');
        $sessionDir = $projectDir.'/.hatfield/sessions/'.$sessionId;
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0777, true);
        }

        $hatfieldSessionStore = $this->sessionStoreForCwd($projectDir);
        $registry = $this->artifactRegistry($hatfieldSessionStore);
        $childDirectory = new AgentChildRunDirectory($hatfieldSessionStore, $registry, new TestLogger());
        $diagStore = $this->diagStore($hatfieldSessionStore, $registry, $childDirectory);
        $catalog = new HatfieldModelCatalog(new AiConfig(
            providers: [
                'openai-codex' => new AiProviderConfig(
                    id: 'openai-codex',
                    type: 'codex',
                    transport: 'websocket',
                    models: ['gpt-5.6' => new AiModelDefinition(id: 'gpt-5.6', name: 'gpt-5.6')],
                ),
                'deepseek' => new AiProviderConfig(
                    id: 'deepseek',
                    type: 'generic',
                    models: ['deepseek-v4-flash' => new AiModelDefinition(id: 'deepseek-v4-flash', name: 'deepseek-v4-flash')],
                ),
            ],
        ));
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PromptCacheDiagnosticsInvocationSubscriber($diagStore, $catalog, new TestLogger(), true));

        $cacheKey = '0194eeee-bbbb-7ccc-8ddd-eeeeeeeeeeee';
        $secret = 'secret-prompt-SHOULD-NOT-PRINT';

        $event1 = $this->dispatchInvocation($dispatcher, $sessionId, 1, 'parent-step-1', 'openai-codex/gpt-5.6', 'stable prologue', $secret, 'read', $cacheKey);
        $this->assertSame('stable prologue', $event1->getInput() instanceof MessageBag
            ? (string) ($event1->getInput()->getSystemMessage()?->getContent() ?? '')
            : '');
        $this->dispatchInvocation($dispatcher, $sessionId, 2, 'parent-step-2', 'openai-codex/gpt-5.6', 'stable prologue', $secret.' follow-up', 'bash', $cacheKey);
        // Thinking-only second invoke on same step: usage attaches only to last diagnostic.
        $this->dispatchInvocation($dispatcher, $sessionId, 2, 'parent-step-2', 'openai-codex/gpt-5.6', 'stable prologue', $secret.' follow-up again', 'bash', $cacheKey);

        $parentRecords = $diagStore->readForRun($sessionId);
        $this->assertCount(3, $parentRecords);
        $serialized = json_encode($parentRecords, \JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($secret, $serialized);
        $this->assertStringNotContainsString($cacheKey, $serialized);
        $this->assertStringNotContainsString('Authorization', $serialized);

        $eventStore = $this->eventStore($hatfieldSessionStore);
        $eventStore->append(RunEvent::forAppend($sessionId, 0, 'run_started', ['metadata' => ['model' => 'openai-codex/gpt-5.6']]));
        $eventStore->append(RunEvent::forAppend($sessionId, 1, 'llm_step_completed', [
            'step_id' => 'parent-step-1',
            'usage' => [
                'input_tokens' => 100,
                'output_tokens' => 10,
                'thinking_tokens' => 5,
                'cache_read_tokens' => 40,
                'cache_creation_tokens' => 10,
                'cost' => 0.12,
            ],
        ]));
        $eventStore->append(RunEvent::forAppend($sessionId, 2, 'llm_step_completed', [
            'step_id' => 'parent-step-2',
            'usage' => [
                'input_tokens' => 200,
                'output_tokens' => 20,
                'thinking_tokens' => 0,
                'cached_tokens' => 80,
                'cost' => 0.34,
            ],
        ]));
        // Historical usage-only event (no sidecar diagnostics).
        $eventStore->append(RunEvent::forAppend($sessionId, 3, 'llm_step_completed', [
            'step_id' => 'parent-step-hist',
            'usage' => ['input_tokens' => 50, 'output_tokens' => 5, 'cost' => 0.01],
        ]));

        $childRunId = '0194ffff-aaaa-7bbb-8ccc-dddddddddddd';
        $artifactId = 'scout-1';
        $entry = $registry->create($sessionId, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $childDirectory->register($entry);
        $this->dispatchInvocation($dispatcher, $childRunId, 1, 'child-step-1', 'deepseek/deepseek-v4-flash', 'child system', 'child-secret-SHOULD-NOT-PRINT', 'read', null);
        $childRecords = $diagStore->readForRun($childRunId);
        $this->assertCount(1, $childRecords);
        $this->assertStringNotContainsString('child-secret-SHOULD-NOT-PRINT', json_encode($childRecords, \JSON_THROW_ON_ERROR));

        $childStore = $this->childEventStoreFactory($hatfieldSessionStore)->create($sessionId, $childRunId, $artifactId);
        $childStore->append(RunEvent::forAppend($childRunId, 0, 'run_started', ['metadata' => ['model' => 'deepseek/deepseek-v4-flash']]));
        $childStore->append(RunEvent::forAppend($childRunId, 1, 'llm_step_completed', [
            'step_id' => 'child-step-1',
            'usage' => ['input_tokens' => 80, 'output_tokens' => 8, 'cache_read_tokens' => 60, 'cost' => 0.02],
        ]));

        $service = new SessionPromptCacheInspectionService(
            $hatfieldSessionStore,
            $eventStore,
            $registry,
            $this->childEventStoreFactory($hatfieldSessionStore),
            $diagStore,
            new TestLogger(),
        );
        $tester = new CommandTester(new SessionCacheInspectCommand($service));
        $exit = $tester->execute(['session-id' => $sessionId]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Per-family summary (not combined)', $display);
        $this->assertStringContainsString('openai-codex', $display);
        $this->assertStringContainsString('websocket', $display);
        $this->assertStringContainsString('deepseek', $display);
        $this->assertStringContainsString('subagent', $display);
        $this->assertStringContainsString('Prefix attribution unavailable', $display);
        $this->assertStringContainsString('local_structure', $display);
        // Parent fingerprinted usage 100+200=300 (double-count prevented despite 2 diags on step-2).
        $this->assertStringContainsString('300', $display);
        $this->assertStringContainsString('0.460000', $display);
        $this->assertStringNotContainsString($secret, $display);
        $this->assertStringNotContainsString($cacheKey, $display);
        $this->assertStringNotContainsString('child-secret-SHOULD-NOT-PRINT', $display);
        $this->assertStringNotContainsString('Authorization', $display);
        $this->assertStringNotContainsString('continuation_delta', $display);
        $this->assertStringNotContainsString('previous_response_id', $display);
    }

    #[Test]
    public function inspectUsageWithoutDiagnosticsShowsOneActionableOptInHint(): void
    {
        $projectDir = getcwd();
        $this->assertNotFalse($projectDir);
        $sessionId = $this->seedSessionRow($projectDir);
        $hatfieldSessionStore = $this->sessionStoreForCwd($projectDir);
        $eventStore = $this->eventStore($hatfieldSessionStore);
        $registry = $this->artifactRegistry($hatfieldSessionStore);
        $eventStore->append(RunEvent::forAppend($sessionId, 1, 'llm_step_completed', [
            'step_id' => 'usage-only-step',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 1, 'cost' => 0.01],
        ]));

        $service = new SessionPromptCacheInspectionService(
            $hatfieldSessionStore,
            $eventStore,
            $registry,
            $this->childEventStoreFactory($hatfieldSessionStore),
            $this->diagStore($hatfieldSessionStore, $registry),
            new TestLogger(),
        );
        $tester = new CommandTester(new SessionCacheInspectCommand($service));

        $this->assertSame(Command::SUCCESS, $tester->execute(['session-id' => $sessionId]));
        $display = $tester->getDisplay();
        $this->assertStringContainsString('Detailed prefix diagnostics were not recorded.', $display);
        $this->assertSame(1, substr_count($display, 'HATFIELD_WRITE_PROMPT_CACHE_DIAGNOSTICS=1'));
        $this->assertStringContainsString('future requests.', $display);
        $this->assertStringContainsString('10', $display);
        $this->assertStringContainsString('Prefix attribution unavailable', $display);
    }

    #[Test]
    public function inspectMissingSessionReturnsFailure(): void
    {
        $projectDir = getcwd();
        $this->assertNotFalse($projectDir);
        $hatfieldSessionStore = $this->sessionStoreForCwd($projectDir);
        $registry = $this->artifactRegistry($hatfieldSessionStore);
        $service = new SessionPromptCacheInspectionService(
            $hatfieldSessionStore,
            $this->eventStore($hatfieldSessionStore),
            $registry,
            $this->childEventStoreFactory($hatfieldSessionStore),
            $this->diagStore($hatfieldSessionStore, $registry),
            new TestLogger(),
        );
        $tester = new CommandTester(new SessionCacheInspectCommand($service));
        $exit = $tester->execute(['session-id' => '999999999']);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    #[Test]
    public function inspectCorruptChildEventsDegradesWithStructuredWarning(): void
    {
        $projectDir = getcwd();
        $this->assertNotFalse($projectDir);
        $sessionId = $this->seedSessionRow($projectDir);
        $sessionDir = $projectDir.'/.hatfield/sessions/'.$sessionId;
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0777, true);
        }

        $hatfieldSessionStore = $this->sessionStoreForCwd($projectDir);
        $eventStore = $this->eventStore($hatfieldSessionStore);
        $registry = $this->artifactRegistry($hatfieldSessionStore);
        $pathResolver = new SessionAgentArtifactPathResolver($hatfieldSessionStore);
        $diagStore = $this->diagStore($hatfieldSessionStore, $registry);

        $eventStore->append(RunEvent::forAppend($sessionId, 1, 'llm_step_completed', [
            'step_id' => 'parent-step',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 1, 'cost' => 0.01],
        ]));

        $childRunId = '0194aaaa-bbbb-7ccc-8ddd-eeeeeeeeeeee';
        $artifactId = 'corrupt-child';
        $registry->create($sessionId, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        file_put_contents($pathResolver->eventsPath($sessionId, $artifactId), "{not-json\n");

        $logger = new TestLogger();
        $service = new SessionPromptCacheInspectionService(
            $hatfieldSessionStore,
            $eventStore,
            $registry,
            $this->childEventStoreFactory($hatfieldSessionStore),
            $diagStore,
            $logger,
        );
        $tester = new CommandTester(new SessionCacheInspectCommand($service));
        $exit = $tester->execute(['session-id' => $sessionId]);

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Per-family summary (not combined)', $tester->getDisplay());
        $this->assertCount(1, $logger->records);
        $this->assertSame('warning', $logger->records[0]['level']);
        $this->assertSame('session.cache_inspect.child_events_unavailable', $logger->records[0]['message']);
        $this->assertSame([
            'component' => 'session_prompt_cache_inspection',
            'event_type' => 'session.cache_inspect.child_events_unavailable',
            'parent_run_id' => $sessionId,
            'run_id' => $childRunId,
            'artifact_id' => $artifactId,
            'exception_class' => \RuntimeException::class,
        ], $logger->records[0]['context']);
    }

    /** @phpstan-ignore-next-line intentional no-op tool target for Tool construction */
    public static function noop(): void
    {
    }

    private function dispatchInvocation(
        EventDispatcher $dispatcher,
        string $runId,
        int $turnNo,
        string $stepId,
        string $model,
        string $system,
        string $user,
        string $toolName,
        ?string $providerCacheKey,
    ): InvocationEvent {
        $bag = new MessageBag(new SystemMessage($system), new UserMessage(new Text($user)));
        $tool = new Tool(
            new ExecutionReference(self::class, 'noop'),
            $toolName,
            'tool description must not leak',
            ['type' => 'object', 'properties' => new \stdClass()],
        );
        $options = ['tools' => [$tool], 'stream' => true];
        if (null !== $providerCacheKey) {
            $options['provider_cache_key'] = $providerCacheKey;
        }
        $options = PlatformInvocationMetadata::inject(
            $options,
            new PlatformInvocationMetadata(
                new ModelInvocationInput(runId: $runId, turnNo: $turnNo, stepId: $stepId),
                new NullCancellationToken(),
            ),
        );

        $event = new InvocationEvent(new Model($model), $bag, $options);
        $beforeOptions = $event->getOptions();
        $beforeInput = $event->getInput();
        $beforeModel = $event->getModel()->getName();
        $dispatcher->dispatch($event);
        // Subscriber must not mutate the InvocationEvent.
        $this->assertSame($beforeOptions, $event->getOptions());
        $this->assertSame($beforeInput, $event->getInput());
        $this->assertSame($beforeModel, $event->getModel()->getName());

        return $event;
    }

    private function seedSessionRow(string $projectDir, string $model = 'openai-codex/gpt-5.6'): string
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $session = new HatfieldSession();
        $session->cwd = $projectDir;
        $session->prompt = 'inspect me';
        $session->name = 'inspect';
        $session->model = $model;
        $em->persist($session);
        $em->flush();

        return (string) $session->id;
    }

    private function sessionStoreForCwd(string $projectDir): HatfieldSessionStore
    {
        return new HatfieldSessionStore(
            appConfig: new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig(), cwd: $projectDir),
            entityManager: static::getContainer()->get('doctrine')->getManager(),
            dispatcher: new EventDispatcher(),
        );
    }

    private function eventStore(HatfieldSessionStore $sessionStore): SessionRunEventStore
    {
        return new SessionRunEventStore(
            hatfieldSessionStore: $sessionStore,
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new TestLogger(),
            sequenceAllocator: new FileRunSequenceAllocator(),
        );
    }

    private function artifactRegistry(HatfieldSessionStore $sessionStore): AgentArtifactRegistry
    {
        $serializer = new Serializer(
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer(classMetadataFactory: new ClassMetadataFactory(new AttributeLoader()), nameConverter: new MetadataAwareNameConverter(new ClassMetadataFactory(new AttributeLoader()), new CamelCaseToSnakeCaseNameConverter()))],
            [new JsonEncoder()],
        );

        return new AgentArtifactRegistry(
            pathResolver: new SessionAgentArtifactPathResolver($sessionStore),
            serializer: $serializer,
            validator: (new ValidatorBuilder())->enableAttributeMapping()->getValidator(),
            lockFactory: new LockFactory(new FlockStore()),
        );
    }

    private function childEventStoreFactory(HatfieldSessionStore $sessionStore): AgentChildRunEventStoreFactory
    {
        return new AgentChildRunEventStoreFactory(
            pathResolver: new SessionAgentArtifactPathResolver($sessionStore),
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new TestLogger(),
            sequenceAllocator: new FileRunSequenceAllocator(),
        );
    }

    private function diagStore(
        HatfieldSessionStore $sessionStore,
        AgentArtifactRegistry $registry,
        ?AgentChildRunDirectory $childDirectory = null,
    ): PromptCacheDiagnosticsStore {
        return new PromptCacheDiagnosticsStore(
            $sessionStore,
            $childDirectory ?? new AgentChildRunDirectory($sessionStore, $registry, new TestLogger()),
            new SessionAgentArtifactPathResolver($sessionStore),
            new TestLogger(),
        );
    }
}
