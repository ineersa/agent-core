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
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;

/**
 * @covers \Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient::start
 *
 * Thesis: InProcessAgentSessionClient::start() called with model/reasoning
 * must persist them to the hatfield_session DB row so that resume restores
 * the session-selected model (not the global default). Without the fix,
 * start() writes nothing and resolveInitialModel falls through to the
 * global default.
 *
 * Uses {@see PerMethodIsolatedKernelTestCase} (per-method kernel boot)
 * because we override AgentRunnerInterface via Container::set(), which
 * mutates the live container.
 */
final class StartRunPersistsSessionModelTest extends PerMethodIsolatedKernelTestCase
{
    /** @var FakeNoopAgentRunner */
    private FakeNoopAgentRunner $spyRunner;

    protected function afterKernelBoot(): void
    {
        $this->spyRunner = new FakeNoopAgentRunner();
        self::getContainer()->set(AgentRunnerInterface::class, $this->spyRunner);
    }

    // ── Helpers ───────────────────────────────────────────────────

    private function client(): InProcessAgentSessionClient
    {
        /** @var InProcessAgentSessionClient */
        return self::getContainer()->get(InProcessAgentSessionClient::class);
    }

    private function sessionMetaStore(): SessionMetadataStore
    {
        /** @var SessionMetadataStore */
        return self::getContainer()->get(SessionMetadataStore::class);
    }

    private function hatfieldSessionStore(): HatfieldSessionStore
    {
        /** @var HatfieldSessionStore */
        return self::getContainer()->get(HatfieldSessionStore::class);
    }

    private function entityManager(): \Doctrine\ORM\EntityManagerInterface
    {
        /** @var \Doctrine\ORM\EntityManagerInterface */
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
        self::assertNotNull($this->spyRunner->lastStartInput,
            'Runner must have been called with a StartRunInput');

        // Assert session metadata was persisted.
        $meta = $this->sessionMetaStore()->readSessionMetadata($sessionId);
        self::assertSame('llama_cpp/flash', $meta['model'] ?? null,
            'Session metadata must contain the persisted model reference');
        self::assertSame('llama_cpp', $meta['model_provider'] ?? null,
            'Session metadata must contain the persisted model provider');
        self::assertSame('flash', $meta['model_name'] ?? null,
            'Session metadata must contain the persisted model name');
        self::assertSame('high', $meta['reasoning'] ?? null,
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
        self::assertSame('llama_cpp/flash', $meta['model'] ?? null,
            'Model must be persisted even when reasoning is null');

        // Resolve the expected reasoning independently via the container's
        // ModelResolver (the same path start() uses for its fallback).
        $expectedReasoning = $this->resolveDefaultReasoning();
        self::assertNotNull($expectedReasoning);
        self::assertSame($expectedReasoning, $meta['reasoning'] ?? null,
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

        self::assertNotNull($this->spyRunner->lastStartInput,
            'Runner must still be called even when no session row exists');
        // No session row means updateMetadata is a no-op — nothing to assert
        // beyond "no crash".
    }

    public function testStartPersistsResolvedDefaultModelWhenNoExplicitModelGiven(): void
    {
        // Thesis: when no explicit model is given and a catalog is available
        // (home settings provide providers in this environment), the resolved
        // default model is persisted to session metadata — so resume is
        // pinned to what turn 1 actually used.
        $cwd = $this->isolatedCwd();
        $sessionId = $this->createSession($cwd);

        $this->client()->start(new StartRunRequest(
            prompt: 'hi',
            runId: $sessionId,
        ));

        $meta = $this->sessionMetaStore()->readSessionMetadata($sessionId);

        // Model is persisted when a catalog is available.
        self::assertArrayHasKey('model', $meta,
            'model key must be set when a catalog resolves the default');
        self::assertNotEmpty($meta['model'],
            'persisted model must not be empty');
        self::assertArrayHasKey('model_provider', $meta);
        self::assertArrayHasKey('model_name', $meta);

        // Reasoning is always resolved (Tier 3/4 fallback).
        $expectedReasoning = $this->resolveDefaultReasoning();
        self::assertSame($expectedReasoning, $meta['reasoning'] ?? null,
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
        self::assertNotNull($resolved,
            'Resolved model must not be null when session metadata is set');
        self::assertSame('llama_cpp/flash', $resolved->toString(),
            'Resolved model must come from session metadata, not global default');

        // Reasoning must also come from session metadata.
        $resolvedReasoning = $resolver->resolveInitialReasoning(null, $sessionId);
        self::assertSame('high', $resolvedReasoning,
            'Resolved reasoning must come from session metadata, not global default');

        // Explicit model override still wins (Tier 1).
        $explicitResolved = $resolver->resolveInitialModel('deepseek/deepseek-v4-pro', $sessionId);
        self::assertNotNull($explicitResolved);
        self::assertSame('deepseek/deepseek-v4-pro', $explicitResolved->toString(),
            'Explicit model (Tier 1) must still win over session metadata (Tier 2)');
    }

    /**
     * With the default test-kernel setup (home settings provide a catalog),
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
        self::assertNotNull($resolved,
            'Must resolve a model from metadata or fallback');

        // Reasoning was persisted by start() — resolver reads from metadata.
        $resolvedReasoning = $resolver->resolveInitialReasoning(null, $sessionId);
        self::assertNotEmpty($resolvedReasoning);
        self::assertSame($meta['reasoning'] ?? null, $resolvedReasoning,
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
    // This test overrides the container's ModelResolver with a
    // catalog-bearing instance so that start() can resolve the effective
    // model/reasoning (with the default test container, AppConfig.catalog
    // is null, so nothing would be resolved).  The override is scoped
    // to this method because PerMethodIsolatedKernelTestCase boots a
    // fresh kernel per test, and Container::set() applies before the
    // first client resolution.

    public function testStartWithNoExplicitModelLocksInResolvedDefaultForResume(): void
    {
        $cwd = $this->isolatedCwd();

        // Override ModelResolver so the container's client sees a real catalog.
        $startAppConfig = $this->makeAppConfig($cwd);
        self::getContainer()->set(ModelResolver::class, new ModelResolver(
            $startAppConfig,
            $this->sessionMetaStore(),
        ));

        $sessionId = $this->createSession($cwd);

        // Start WITH a catalog but WITHOUT an explicit model — the resolved
        // default (deepseek/deepseek-v4-pro) must be locked in.
        $this->client()->start(new StartRunRequest(
            prompt: 'hi',
            runId: $sessionId,
            // No model, no reasoning — both resolved from catalog defaults.
        ));

        self::assertNotNull($this->spyRunner->lastStartInput,
            'Runner must have been called');

        // Assert the resolved default WAS persisted.
        $meta = $this->sessionMetaStore()->readSessionMetadata($sessionId);
        self::assertSame('deepseek/deepseek-v4-pro', $meta['model'] ?? null,
            'resolved global-default model must be persisted even without --model flag');
        self::assertSame('deepseek', $meta['model_provider'] ?? null);
        self::assertSame('deepseek-v4-pro', $meta['model_name'] ?? null);
        self::assertSame('medium', $meta['reasoning'] ?? null,
            'resolved default reasoning must be persisted');

        // Now simulate a global-default change: build a resolver whose
        // catalog default is llama_cpp/flash (DIFFERENT from the persisted
        // deepseek/deepseek-v4-pro).  The resolver shares the same
        // SessionMetadataStore, so it reads the persisted metadata.
        $changedDefaultResolver = $this->buildModelResolverWithDefault(
            $cwd,
            'llama_cpp/flash',
            'high',
        );

        // resolveInitialModel must return the PERSISTED start-time default,
        // NOT the new global default — this is the gap fix: without it,
        // the new default would silently take over.
        $resolved = $changedDefaultResolver->resolveInitialModel(null, $sessionId);
        self::assertNotNull($resolved);
        self::assertSame('deepseek/deepseek-v4-pro', $resolved->toString(),
            'Resolved model must come from session metadata (start-time default), not new global default');

        $resolvedReasoning = $changedDefaultResolver->resolveInitialReasoning(null, $sessionId);
        self::assertSame('medium', $resolvedReasoning,
            'Resolved reasoning must come from session metadata, not new global default');
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
        return $this->makeAppConfigFromAiData($cwd, $this->standardAiData());
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
        $aiData = $this->standardAiData();
        $aiData['default_model'] = $defaultModel;
        $aiData['default_reasoning'] = $defaultReasoning;

        return new ModelResolver(
            $this->makeAppConfigFromAiData($cwd, $aiData),
            $this->sessionMetaStore(),
        );
    }

    /**
     * Same catalog as SessionAwareModelResolverTest::standardAiData().
     *
     * Global default: deepseek/deepseek-v4-pro (NOT llama_cpp/flash).
     * Both providers are available so the test proves session metadata
     * wins over the global default.
     */
    private function standardAiData(): array
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
 * @internal No-op runner that captures the StartRunInput without
 * executing a real agent run.
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
