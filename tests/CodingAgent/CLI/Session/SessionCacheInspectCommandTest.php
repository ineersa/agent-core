<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Session;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Diagnostics\SessionPromptCacheInspectionService;
use Ineersa\CodingAgent\CLI\Session\SessionCacheInspectCommand;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Platform\Diagnostics\PromptCacheRequestDiagnosticsRecorder;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Component\Serializer\Normalizer\BackedEnumNormalizer;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ValidatorBuilder;

/**
 * Thesis: session:cache:inspect reports per-family usage arithmetic, keeps
 * parent/child attribution separate, degrades honestly without fingerprints,
 * remains visible, and never prints raw secrets/cache keys.
 */
final class SessionCacheInspectCommandTest extends IsolatedKernelTestCase
{
    #[Test]
    public function inspectReportsFamiliesChildAttributionHistoricalFallbackAndPrivacy(): void
    {
        $projectDir = getcwd();
        $this->assertNotFalse($projectDir);
        $sessionId = $this->seedSessionRow($projectDir);
        $sessionsBase = $projectDir.'/.hatfield/sessions';
        mkdir($sessionsBase.'/'.$sessionId, 0777, true);

        $hatfieldSessionStore = $this->sessionStoreForCwd($projectDir);
        $eventStore = $this->eventStore($hatfieldSessionStore);
        $registry = $this->artifactRegistry($hatfieldSessionStore);
        $childFactory = $this->childEventStoreFactory($hatfieldSessionStore);

        $recorder = new PromptCacheRequestDiagnosticsRecorder();
        $cacheKey = '0194eeee-bbbb-7ccc-8ddd-eeeeeeeeeeee';
        $recorder->record([
            'instructions' => 'stable prologue',
            'tools' => [['type' => 'function', 'name' => 'read']],
            'input' => [['role' => 'user', 'content' => 'secret-prompt-SHOULD-NOT-PRINT']],
            'prompt_cache_key' => $cacheKey,
        ], 'openai-codex', 'websocket-cached', $cacheKey, [
            'mode' => 'full_context',
            'model' => 'openai-codex/gpt-5.6',
            'prompt_cache_key_present' => true,
            'previous_response_id_present' => false,
            'wire_input_count' => 1,
        ]);
        $recorder->record([
            'instructions' => 'stable prologue',
            'tools' => [['type' => 'function', 'name' => 'bash']],
            'input' => [
                ['role' => 'user', 'content' => 'secret-prompt-SHOULD-NOT-PRINT'],
                ['role' => 'user', 'content' => 'follow-up'],
            ],
            'prompt_cache_key' => $cacheKey,
            'previous_response_id' => 'resp_1',
        ], 'openai-codex', 'websocket-cached', $cacheKey, [
            'mode' => 'continuation_delta',
            'model' => 'openai-codex/gpt-5.6',
            'prompt_cache_key_present' => true,
            'previous_response_id_present' => true,
            'wire_input_count' => 1,
        ]);
        $records = $recorder->records();

        $eventStore->append(RunEvent::forAppend(
            runId: $sessionId,
            turnNo: 0,
            type: 'run_started',
            payload: ['metadata' => ['model' => 'openai-codex/gpt-5.6']],
        ));
        $eventStore->append(RunEvent::forAppend(
            runId: $sessionId,
            turnNo: 1,
            type: 'llm_step_completed',
            payload: [
                'step_id' => 'parent-step-1',
                'usage' => [
                    'input_tokens' => 100,
                    'output_tokens' => 10,
                    'thinking_tokens' => 5,
                    'cache_read_tokens' => 40,
                    'cache_creation_tokens' => 10,
                    'cost' => 0.12,
                ],
                'request_diagnostics' => [$records[0]],
            ],
        ));
        $eventStore->append(RunEvent::forAppend(
            runId: $sessionId,
            turnNo: 2,
            type: 'llm_step_completed',
            payload: [
                'step_id' => 'parent-step-2',
                'usage' => [
                    'input_tokens' => 200,
                    'output_tokens' => 20,
                    'thinking_tokens' => 0,
                    'cached_tokens' => 80,
                    'cost' => 0.34,
                ],
                'request_diagnostics' => [$records[1]],
            ],
        ));
        // Historical usage-only event (no fingerprints).
        $eventStore->append(RunEvent::forAppend(
            runId: $sessionId,
            turnNo: 3,
            type: 'llm_step_completed',
            payload: [
                'step_id' => 'parent-step-hist',
                'usage' => [
                    'input_tokens' => 50,
                    'output_tokens' => 5,
                    'cost' => 0.01,
                ],
            ],
        ));

        $childRunId = '0194ffff-aaaa-7bbb-8ccc-dddddddddddd';
        $artifactId = 'scout-1';
        $registry->create($sessionId, $artifactId, $childRunId, 'scout', AgentArtifactKindEnum::Subagent);
        $childStore = $childFactory->create($sessionId, $childRunId, $artifactId);
        $childStore->append(RunEvent::forAppend(
            runId: $childRunId,
            turnNo: 0,
            type: 'run_started',
            payload: ['metadata' => ['model' => 'deepseek/deepseek-v4-flash']],
        ));
        $childStore->append(RunEvent::forAppend(
            runId: $childRunId,
            turnNo: 1,
            type: 'llm_step_completed',
            payload: [
                'step_id' => 'child-step-1',
                'usage' => [
                    'input_tokens' => 80,
                    'output_tokens' => 8,
                    'cache_read_tokens' => 60,
                    'cost' => 0.02,
                ],
                'request_diagnostics' => [[
                    'provider' => 'deepseek',
                    'transport' => 'http',
                    'model' => 'deepseek/deepseek-v4-flash',
                    'mode' => 'full_context',
                    'prompt_cache_key_present' => false,
                    'previous_response_id_present' => false,
                    'wire_input_count' => 2,
                    'cache_family_fp' => hash_hmac('sha256', $childRunId, $childRunId),
                    'request_hmac' => hash_hmac('sha256', 'child-body', $childRunId),
                    'request_bytes' => 12,
                    'components' => [[
                        'section' => 'messages',
                        'type' => null,
                        'role' => 'user',
                        'name' => null,
                        'hmac' => hash_hmac('sha256', 'u', $childRunId),
                        'bytes' => 1,
                    ]],
                ]],
            ],
        ));

        $service = new SessionPromptCacheInspectionService(
            $hatfieldSessionStore,
            $eventStore,
            $registry,
            $childFactory,
        );
        $tester = new CommandTester(new SessionCacheInspectCommand($service));
        $exit = $tester->execute(['session-id' => $sessionId]);
        $display = $tester->getDisplay();

        $this->assertSame(Command::SUCCESS, $exit);
        $this->assertStringContainsString('Per-family summary (not combined)', $display);
        $this->assertStringContainsString('openai-codex', $display);
        $this->assertStringContainsString('websocket-cached', $display);
        $this->assertStringContainsString('deepseek', $display);
        $this->assertStringContainsString('subagent', $display);
        $this->assertStringContainsString('Prefix attribution unavailable', $display);
        $this->assertStringContainsString('continuation_delta', $display);
        $this->assertStringContainsString('local_structure', $display);
        // 100+200 input with fingerprints; historical 50 is separate family.
        $this->assertStringContainsString('300', $display);
        $this->assertStringContainsString('0.460000', $display);
        // Privacy: no raw prompt/cache key.
        $this->assertStringNotContainsString('secret-prompt-SHOULD-NOT-PRINT', $display);
        $this->assertStringNotContainsString($cacheKey, $display);
        $this->assertStringNotContainsString('Authorization', $display);
    }

    #[Test]
    public function inspectMissingSessionReturnsFailure(): void
    {
        $projectDir = getcwd();
        $this->assertNotFalse($projectDir);
        $service = new SessionPromptCacheInspectionService(
            $this->sessionStoreForCwd($projectDir),
            $this->eventStore($this->sessionStoreForCwd($projectDir)),
            $this->artifactRegistry($this->sessionStoreForCwd($projectDir)),
            $this->childEventStoreFactory($this->sessionStoreForCwd($projectDir)),
        );
        $tester = new CommandTester(new SessionCacheInspectCommand($service));
        $exit = $tester->execute(['session-id' => '999999999']);

        $this->assertSame(Command::FAILURE, $exit);
        $this->assertStringContainsString('not found', $tester->getDisplay());
    }

    private function seedSessionRow(string $projectDir): string
    {
        $em = static::getContainer()->get('doctrine')->getManager();
        $session = new HatfieldSession();
        $session->cwd = $projectDir;
        $session->prompt = 'inspect me';
        $session->name = 'inspect';
        $session->model = 'openai-codex/gpt-5.6';
        $em->persist($session);
        $em->flush();

        return (string) $session->id;
    }

    private function sessionStoreForCwd(string $projectDir): HatfieldSessionStore
    {
        return new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $projectDir,
            ),
            entityManager: static::getContainer()->get('doctrine')->getManager(),
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
            [new DateTimeNormalizer(), new BackedEnumNormalizer(), new ObjectNormalizer(
                nameConverter: new CamelCaseToSnakeCaseNameConverter(),
            )],
            [new JsonEncoder()],
        );

        return new AgentArtifactRegistry(
            pathResolver: new AgentArtifactPathResolver(new SessionAgentArtifactPathResolver($sessionStore)),
            serializer: $serializer,
            validator: (new ValidatorBuilder())->enableAttributeMapping()->getValidator(),
            lockFactory: new LockFactory(new FlockStore()),
        );
    }

    private function childEventStoreFactory(HatfieldSessionStore $sessionStore): AgentChildRunEventStoreFactory
    {
        return new AgentChildRunEventStoreFactory(
            pathResolver: new AgentArtifactPathResolver(new SessionAgentArtifactPathResolver($sessionStore)),
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new TestLogger(),
            sequenceAllocator: new FileRunSequenceAllocator(),
        );
    }
}
