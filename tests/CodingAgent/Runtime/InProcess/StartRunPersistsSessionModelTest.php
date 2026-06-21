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
use Ineersa\CodingAgent\Config\SettingsPathResolver;
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

    public function testStartPersistsOnlyModelWhenNoReasoningGiven(): void
    {
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
        self::assertArrayNotHasKey('reasoning', $meta,
            'Reasoning must NOT be written when null in the request');
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

    public function testStartLeavesMetadataUntouchedWhenNoModelOrReasoning(): void
    {
        $cwd = $this->isolatedCwd();
        $sessionId = $this->createSession($cwd);

        // Start with no model/reasoning — nothing should be persisted.
        $this->client()->start(new StartRunRequest(
            prompt: 'hi',
            runId: $sessionId,
            model: null,
            reasoning: null,
        ));

        $meta = $this->sessionMetaStore()->readSessionMetadata($sessionId);

        // model keys should not exist (the entity defaults to null).
        self::assertArrayNotHasKey('model', $meta,
            'model key must not be set when no model was given');
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

    public function testResolveInitialModelFallsBackToDefaultForSessionWithoutMetadata(): void
    {
        $cwd = $this->isolatedCwd();
        $sessionId = $this->createSession($cwd);

        // Start WITHOUT model/reasoning.
        $this->client()->start(new StartRunRequest(
            prompt: 'hi',
            runId: $sessionId,
        ));

        $resolver = $this->buildModelResolver($cwd);

        // No model in session metadata → must fall back to global default.
        $resolved = $resolver->resolveInitialModel(null, $sessionId);
        self::assertNotNull($resolved,
            'Resolved model must fall back to global default when no session metadata');
        self::assertSame('deepseek/deepseek-v4-pro', $resolved->toString(),
            'Fallback must be the global default model');

        $resolvedReasoning = $resolver->resolveInitialReasoning(null, $sessionId);
        self::assertSame('medium', $resolvedReasoning,
            'Fallback reasoning must be the global default reasoning');
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
        $aiData = $this->standardAiData();
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
