<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * @covers \Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient::start
 *
 * Thesis: InProcessAgentSessionClient::start() called with model/reasoning
 * must persist them to the hatfield_session DB row so that resume restores
 * the session-selected model (not the global default). Without the fix,
 * start() writes nothing and resolveInitialModel falls through to the
 * global default.
 *
 * Uses {@see IsolatedKernelTestCase} (per-class kernel boot, the
 * project-preferred base for DB tests).  The spy runner is installed
 * once in {@see setUpBeforeClass()} before any service access
 * (TestContainer rejects replacements of already-initialized services);
 * {@see setUp()} only fetches the shared instance.  No test overrides the container's
 * ModelResolver — the start-time default is verified via the container's
 * own resolver against manually-built resolvers with different defaults.
 */
final class StartRunPersistsSessionModelTest extends IsolatedKernelTestCase
{
    private FakeNoopAgentRunner $spyRunner;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Replace AgentRunnerInterface with a spy BEFORE any test
        // accesses it.  With IsolatedKernelTestCase (per-class boot,
        // shared container), services can only be replaced before
        // first access — after that TestContainer rejects replacements.
        // setUpBeforeClass runs before any test method, so the spy is
        // in place before the first client() resolution.
        self::getContainer()->set(AgentRunnerInterface::class, new FakeNoopAgentRunner());
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Fetch the spy runner installed in setUpBeforeClass.  The
        // container is shared across methods, so this returns the
        // same spy instance each time.  lastStartInput is overwritten
        // by each start() call, so no per-method re-install is needed.
        /* @var FakeNoopAgentRunner */
        $this->spyRunner = self::getContainer()->get(AgentRunnerInterface::class);
    }

    // ── Persistence tests ─────────────────────────────────────────

    public function testStartPersistsModelAndReasoningToSessionMetadata(): void
    {
        $cwd = $this->isolatedCwd();
        $sessionId = $this->createSession($cwd);

        $this->client()->start(new StartRunRequest(
            prompt: 'hi',
            runId: $sessionId,
            model: 'llama_cpp/flash',
            reasoning: 'high',
        ));

        // Verify the runner was called (proves start() executed).
        $this->assertNotNull($this->spyRunner->lastStartInput,
            'Runner must have been called with a StartRunInput');

        // Assert session metadata was persisted.
        $meta = $this->sessionMetaStore()->readSessionMetadata($sessionId);
        $this->assertSame('llama_cpp/flash', $meta['model'] ?? null,
            'Session metadata must contain the persisted model reference');
        $this->assertSame('llama_cpp', $meta['model_provider'] ?? null,
            'Session metadata must contain the persisted model provider');
        $this->assertSame('flash', $meta['model_name'] ?? null,
            'Session metadata must contain the persisted model name');
        $this->assertSame('high', $meta['reasoning'] ?? null,
            'Session metadata must contain the persisted reasoning level');
    }

    public function testStartPersistsExplicitModelAndResolvedDefaultReasoning(): void
    {
        // Thesis: when an explicit model is given without reasoning,
        // the model is persisted as-is (catalog-free tryParse) and the
        // resolved default reasoning is persisted so resume restores
        // both consistently (no drift from a later global default change).
        $cwd = $this->isolatedCwd();
        $sessionId = $this->createSession($cwd);

        $this->client()->start(new StartRunRequest(
            prompt: 'hi',
            runId: $sessionId,
            model: 'llama_cpp/flash',
            reasoning: null,
        ));

        $meta = $this->sessionMetaStore()->readSessionMetadata($sessionId);
        $this->assertSame('llama_cpp/flash', $meta['model'] ?? null,
            'Model must be persisted even when reasoning is null');

        // Resolve the expected reasoning independently via the container's
        // ModelResolver (the same path start() uses for its fallback).
        $expectedReasoning = $this->resolveDefaultReasoning();
        $this->assertNotNull($expectedReasoning);
        $this->assertSame($expectedReasoning, $meta['reasoning'] ?? null,
            'Resolved default reasoning must be persisted when no explicit reasoning given');
    }

    public function testStartDoesNotWriteMetadataForNewSessionWithoutRunId(): void
    {
        // When runId is empty, no session row exists — guard must skip persistence.
        $this->client()->start(new StartRunRequest(
            prompt: 'hi',
            runId: '',
            model: 'llama_cpp/flash',
            reasoning: 'high',
        ));

        $this->assertNotNull($this->spyRunner->lastStartInput,
            'Runner must still be called even when no session row exists');

        // No metadata must be written when no session row exists.
        $meta = $this->hatfieldSessionStore()->loadMetadata('');
        $this->assertEmpty($meta,
            'No session metadata must be written for an empty session ID');
    }

    public function testStartPinsResolvedModelInRunStartedMetadata(): void
    {
        $cwd = $this->isolatedCwd();
        $sessionId = $this->createSession($cwd);

        $this->client()->start(new StartRunRequest(
            prompt: 'hi',
            runId: $sessionId,
        ));

        $this->assertNotNull($this->spyRunner->lastStartInput);
        $metadata = $this->spyRunner->lastStartInput->metadata;
        $this->assertNotNull($metadata, 'StartRunInput metadata must carry resolved model when request omits model');
        $this->assertNotNull($metadata->model);
        $this->assertNotSame('', trim((string) $metadata->model));

        $sessionMeta = $this->sessionMetaStore()->readSessionMetadata($sessionId);
        $this->assertSame($metadata->model, $sessionMeta['model'] ?? null);
        $this->assertSame($metadata->reasoning, $sessionMeta['reasoning'] ?? null);
    }

    public function testStartPersistsResolvedDefaultModelWhenNoExplicitModelGiven(): void
    {
        // Thesis: when no explicit model is given and the isolated project
        // settings provide a model catalog, the resolved default model is
        // persisted to session metadata — so resume is pinned to what turn 1
        // actually used.
        $cwd = $this->isolatedCwd();
        $sessionId = $this->createSession($cwd);

        $this->client()->start(new StartRunRequest(
            prompt: 'hi',
            runId: $sessionId,
        ));

        $meta = $this->sessionMetaStore()->readSessionMetadata($sessionId);

        // Model is persisted when a catalog is available.
        $this->assertArrayHasKey('model', $meta,
            'model key must be set when a catalog resolves the default');
        $this->assertNotEmpty($meta['model'],
            'persisted model must not be empty');
        $this->assertArrayHasKey('model_provider', $meta);
        $this->assertArrayHasKey('model_name', $meta);

        // Reasoning is always resolved (Tier 3/4 fallback).
        $expectedReasoning = $this->resolveDefaultReasoning();
        $this->assertSame($expectedReasoning, $meta['reasoning'] ?? null,
            'resolved default reasoning must be persisted');
    }

    // ── Resume contract test ──────────────────────────────────────
    //
    // Proves that the persisted session metadata is actually used
    // by ModelResolver on resume — the Tier-2 (session metadata)
    // read path wins over Tier-3 (global default).

    public function testResolveInitialModelReturnsPersistedSessionModel(): void
    {
        $cwd = $this->isolatedCwd();
        $sessionId = $this->createSession($cwd);

        // Persist model via start() — the fix under test.
        $this->client()->start(new StartRunRequest(
            prompt: 'hi',
            runId: $sessionId,
            model: 'llama_cpp/flash',
            reasoning: 'high',
        ));

        // Build a ModelResolver with a DIFFERENT global default
        // (deepseek/deepseek-v4-pro) so we can prove session metadata wins.
        $resolver = $this->buildModelResolver($cwd);

        // No explicit model → must resolve from session metadata (Tier 2),
        // NOT from the global default (Tier 3).
        $resolved = $resolver->resolveInitialModel(null, $sessionId);
        $this->assertNotNull($resolved,
            'Resolved model must not be null when session metadata is set');
        $this->assertSame('llama_cpp/flash', $resolved->toString(),
            'Resolved model must come from session metadata, not global default');

        // Reasoning must also come from session metadata.
        $resolvedReasoning = $resolver->resolveInitialReasoning(null, $sessionId);
        $this->assertSame('high', $resolvedReasoning,
            'Resolved reasoning must come from session metadata, not global default');

        // Explicit model override still wins (Tier 1).
        $explicitResolved = $resolver->resolveInitialModel('deepseek/deepseek-v4-pro', $sessionId);
        $this->assertNotNull($explicitResolved);
        $this->assertSame('deepseek/deepseek-v4-pro', $explicitResolved->toString(),
            'Explicit model (Tier 1) must still win over session metadata (Tier 2)');
    }

    /**
     * With this test's isolated project settings providing a catalog,
     * start() persists the resolved default model to session metadata.
     * The independent resolver (built with standardAiData, default =
     * deepseek/deepseek-v4-pro) reads the persisted model from metadata
     * (Tier 2) when it is available in its own catalog.  Reasoning is
     * also read from metadata.
     */
    public function testResolverUsesPersistedMetadataFromStart(): void
    {
        $cwd = $this->isolatedCwd();
        $sessionId = $this->createSession($cwd);

        // Start WITHOUT model/reasoning.
        $this->client()->start(new StartRunRequest(
            prompt: 'hi',
            runId: $sessionId,
        ));

        $meta = $this->sessionMetaStore()->readSessionMetadata($sessionId);

        $resolver = $this->buildModelResolver($cwd);

        // A model is always resolved — either from persisted metadata
        // (Tier 2) or from the resolver's global default (Tier 3).
        $resolved = $resolver->resolveInitialModel(null, $sessionId);
        $this->assertNotNull($resolved,
            'Must resolve a model from metadata or fallback');

        // Reasoning was persisted by start() — resolver reads from metadata.
        $resolvedReasoning = $resolver->resolveInitialReasoning(null, $sessionId);
        $this->assertNotEmpty($resolvedReasoning);
        $this->assertSame($meta['reasoning'] ?? null, $resolvedReasoning,
            'Resolved reasoning must match persisted session metadata');
    }

    // ── Gap proof: no-explicit-model start pins the resolved default ─
    //
    // Thesis: when start() is called without an explicit model AND a
    // catalog is available, the resolved global default model/reasoning
    // is persisted to session metadata.  A later resolveInitialModel
    // (simulating resume with a different global default) must return
    // the PERSISTED value — not the new global default.
    //
    // Instead of overriding the container's ModelResolver (which fails
    // with per-class kernel boot because the resolver is already
    // initialized by earlier tests), this test uses the container's
    // natural resolver to discover the resolved default, then builds
    // a second resolver with intentionally different defaults to prove
    // the persisted start-time value wins.

    public function testStartWithNoExplicitModelLocksInResolvedDefaultForResume(): void
    {
        $cwd = $this->isolatedCwd();
        $sessionId = $this->createSession($cwd);

        // Start WITHOUT an explicit model — the resolved default
        // (whatever the container's catalog provides) must be locked in.
        $this->client()->start(new StartRunRequest(
            prompt: 'hi',
            runId: $sessionId,
            // No model, no reasoning — both resolved from catalog defaults.
        ));

        $this->assertNotNull($this->spyRunner->lastStartInput,
            'Runner must have been called');

        // Use the container's natural ModelResolver to find out what
        // default was resolved — the same resolution path start() used.
        /** @var ModelResolver $containerResolver */
        $containerResolver = self::getContainer()->get(ModelResolver::class);
        $expectedRef = $containerResolver->resolveInitialModel(null, $sessionId);
        $this->assertNotNull($expectedRef,
            'Container resolver must resolve a default model when a catalog is available');
        $expectedReasoning = $containerResolver->resolveInitialReasoning(null, $sessionId);
        $this->assertNotEmpty($expectedReasoning);

        // Assert the resolved defaults WERE persisted.
        $meta = $this->sessionMetaStore()->readSessionMetadata($sessionId);
        $this->assertSame($expectedRef->toString(), $meta['model'] ?? null,
            'resolved global-default model must be persisted even without --model flag');
        $this->assertSame($expectedRef->providerId, $meta['model_provider'] ?? null);
        $this->assertSame($expectedRef->modelName, $meta['model_name'] ?? null);
        $this->assertSame($expectedReasoning, $meta['reasoning'] ?? null,
            'resolved default reasoning must be persisted');

        // Now simulate a global-default change: build a resolver whose
        // catalog default is DIFFERENT from the persisted value.
        $differentModel = 'llama_cpp/flash' === $expectedRef->toString()
            ? 'deepseek/deepseek-v4-pro'
            : 'llama_cpp/flash';
        $differentReasoning = 'medium' === $expectedReasoning ? 'high' : 'medium';
        $changedDefaultResolver = $this->buildModelResolverWithDefault(
            $cwd,
            $differentModel,
            $differentReasoning,
        );

        // resolveInitialModel must return the PERSISTED start-time default,
        // NOT the new global default — this is the gap fix: without it,
        // the new default would silently take over.
        $resolved = $changedDefaultResolver->resolveInitialModel(null, $sessionId);
        $this->assertNotNull($resolved);
        $this->assertSame($expectedRef->toString(), $resolved->toString(),
            'Resolved model must come from session metadata (start-time default), not new global default');

        $resolvedReasoning = $changedDefaultResolver->resolveInitialReasoning(null, $sessionId);
        $this->assertSame($expectedReasoning, $resolvedReasoning,
            'Resolved reasoning must come from session metadata, not new global default');
    }

    protected static function configureIsolatedProjectBeforeKernelBoot(string $classCwd): void
    {
        self::writeIsolatedProjectAiSettings($classCwd);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function client(): InProcessAgentSessionClient
    {
        /* @var InProcessAgentSessionClient */
        return self::getContainer()->get(InProcessAgentSessionClient::class);
    }

    private function sessionMetaStore(): SessionMetadataStore
    {
        /* @var SessionMetadataStore */
        return self::getContainer()->get(SessionMetadataStore::class);
    }

    private function hatfieldSessionStore(): HatfieldSessionStore
    {
        /* @var HatfieldSessionStore */
        return self::getContainer()->get(HatfieldSessionStore::class);
    }

    private function entityManager(): \Doctrine\ORM\EntityManagerInterface
    {
        /* @var \Doctrine\ORM\EntityManagerInterface */
        return self::getContainer()->get('doctrine.orm.default_entity_manager');
    }

    /**
     * Resolve the default reasoning level via the container's ModelResolver.
     *
     * Uses an empty sessionId so the resolver does not read existing
     * session metadata — only the configured default (or 'medium' fallback).
     */
    private function resolveDefaultReasoning(string $sessionId = ''): string
    {
        /** @var ModelResolver */
        $resolver = self::getContainer()->get(ModelResolver::class);

        return $resolver->resolveInitialReasoning(null, $sessionId);
    }

    /**
     * Create a session entity and return its string ID.
     *
     * Mirrors SessionAwareModelResolverTest::writeSessionMetadata.
     * No public_id column — the integer primary key is the canonical
     * identifier and its string form is the external session ID.
     */
    private function createSession(string $cwd): string
    {
        $entity = new HatfieldSession();
        $entity->cwd = $cwd;
        $this->entityManager()->persist($entity);
        $this->entityManager()->flush();

        return (string) $entity->id;
    }

    // ── ModelResolver builder ─────────────────────────────────────
    //
    // Constructs a ModelResolver with a test catalog where the global
    // default is deepseek/deepseek-v4-pro and both deepseek and llama_cpp
    // providers are available — exactly mirroring standardAiData() from
    // SessionAwareModelResolverTest.

    private function buildModelResolver(string $cwd): ModelResolver
    {
        $appConfig = $this->makeAppConfig($cwd);

        return new ModelResolver(
            $appConfig,
            $this->sessionMetaStore(),
        );
    }

    private function makeAppConfig(string $cwd): AppConfig
    {
        return $this->makeAppConfigFromAiData($cwd, self::standardAiData());
    }

    private function makeAppConfigFromAiData(string $cwd, array $aiData): AppConfig
    {
        $ai = AiConfig::optionalFromArray(['tui' => ['theme' => 'cyberpunk'], 'ai' => $aiData]);

        return new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            ai: $ai,
            raw: ['ai' => $aiData],
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: $cwd,
        );
    }

    /**
     * Build a ModelResolver whose catalog has a different global default
     * — used to simulate a global-default change between session start
     * and resume.
     */
    private function buildModelResolverWithDefault(
        string $cwd,
        string $defaultModel,
        string $defaultReasoning,
    ): ModelResolver {
        $aiData = self::standardAiData();
        $aiData['default_model'] = $defaultModel;
        $aiData['default_reasoning'] = $defaultReasoning;

        return new ModelResolver(
            $this->makeAppConfigFromAiData($cwd, $aiData),
            $this->sessionMetaStore(),
        );
    }

    /**
     * Seed isolated project Hatfield settings with a minimal AI catalog so
     * kernel boot does not depend on the developer's ~/.hatfield/providers.
     */
    private static function writeIsolatedProjectAiSettings(string $classCwd): void
    {
        $settings = [
            'ai' => self::standardAiData(),
        ];

        file_put_contents(
            $classCwd.'/.hatfield/settings.yaml',
            '# hatfield settings (test isolation)
'.Yaml::dump($settings, 4, 2),
        );
    }

    /**
     * Same catalog as SessionAwareModelResolverTest::standardAiData().
     *
     * Global default: deepseek/deepseek-v4-pro (NOT llama_cpp/flash).
     * Both providers are available so the test proves session metadata
     * wins over the global default.
     */
    private static function standardAiData(): array
    {
        return [
            'default_model' => 'deepseek/deepseek-v4-pro',
            'default_reasoning' => 'medium',
            'providers' => [
                'deepseek' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'https://api.deepseek.com',
                    'completions_path' => '/chat/completions',
                    'models' => [
                        'deepseek-v4-pro' => [
                            'id' => 'deepseek-v4-pro',
                            'name' => 'DeepSeek V4 Pro',
                            'context_window' => 131072,
                            'max_tokens' => 131072,
                            'input' => ['text'],
                            'reasoning' => true,
                        ],
                        'deepseek-v4-flash' => [
                            'id' => 'deepseek-v4-flash',
                            'name' => 'DeepSeek V4 Flash',
                            'context_window' => 131072,
                            'max_tokens' => 131072,
                            'input' => ['text'],
                            'reasoning' => false,
                        ],
                    ],
                ],
                'llama_cpp' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'http://192.168.2.38:8052/v1',
                    'models' => [
                        'flash' => [
                            'id' => 'flash',
                            'name' => 'Flash',
                            'context_window' => 200000,
                            'max_tokens' => 65536,
                            'input' => ['text', 'image'],
                            'reasoning' => false,
                        ],
                    ],
                ],
            ],
        ];
    }
}

/**
 * @internal no-op runner that captures the StartRunInput without
 * executing a real agent run
 */
final class FakeNoopAgentRunner implements AgentRunnerInterface
{
    public ?StartRunInput $lastStartInput = null;

    public function start(StartRunInput $input): string
    {
        $this->lastStartInput = $input;

        return $input->runId ?? 'fake-run-id';
    }

    public function continue(string $runId): void
    {
    }

    public function steer(string $runId, AgentMessage $message): void
    {
    }

    public function followUp(string $runId, AgentMessage $message): void
    {
    }

    public function appendMessage(string $runId, AgentMessage $message): void
    {
    }

    public function cancel(string $runId, ?string $reason = null): void
    {
    }

    public function answerHuman(string $runId, string $questionId, mixed $answer): void
    {
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
    }
}
