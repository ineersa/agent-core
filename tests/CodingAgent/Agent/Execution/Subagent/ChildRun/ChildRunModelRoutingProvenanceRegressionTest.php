<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun;

use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Contract\Model\RunModelResolverInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\Component\Yaml\Yaml;

/**
 * P0 cost-safety regression for session-33 model drift.
 *
 * Thesis: a deferred subagent child launched with DeepSeek must invoke the
 * platform with that same DeepSeek model even when:
 *  - the child run UUID has a numeric prefix that collides with a normal
 *    hatfield_session primary key;
 *  - the global/default model is expensive Codex;
 *  - ExecuteLlmStep is scheduled and handled by the real worker path.
 *
 * Without production model-envelope propagation this fails because the worker
 * late-resolves via session lookup / default model and can cast the UUID
 * prefix to an unrelated numeric session id.
 */
final class ChildRunModelRoutingProvenanceRegressionTest extends IsolatedKernelTestCase
{
    private const string CHILD_RUN_ID = '3d451a76-e371-5ece-b9ca-8769167d85e4';
    private const string CHILD_MODEL = 'deepseek/deepseek-v4-flash';
    private const string DEFAULT_CODEX_MODEL = 'openai-codex/gpt-5.6-sol';
    private const string COLLIDING_SESSION_MODEL = 'openai-codex/gpt-5.6-luna';

    public function testChildUuidNumericPrefixDoesNotDriftFromDeepSeekToCodexOnExecuteLlmStep(): void
    {
        $entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        \assert($entityManager instanceof \Doctrine\ORM\EntityManagerInterface);

        // Seed normal sessions 1..3 so (int) '3d451...' resolves to session 3.
        $collidingSessionId = null;
        for ($i = 0; $i < 3; ++$i) {
            $session = new HatfieldSession();
            $session->cwd = $this->isolatedCwd();
            if (2 === $i) {
                $session->model = self::COLLIDING_SESSION_MODEL;
            }
            $entityManager->persist($session);
            $entityManager->flush();
            if (2 === $i) {
                $collidingSessionId = (string) $session->id;
            }
        }
        $this->assertSame('3', $collidingSessionId, 'Fixture must place colliding numeric session at id 3.');
        $this->assertSame(3, (int) self::CHILD_RUN_ID, 'Child UUID prefix must cast to session id 3.');

        $childRepo = self::getContainer()->get(DeferredSubagentChildRepository::class);
        \assert($childRepo instanceof DeferredSubagentChildRepository);
        $childRepo->insertReservedChildren('batch-model-routing-red', [[
            'batchIndex' => 1,
            'childRunId' => self::CHILD_RUN_ID,
            'artifactId' => 'agent_dd2f69d9cb4c075c',
            'agentName' => 'scout',
            'task' => 'investigate model routing',
            'definitionModel' => self::CHILD_MODEL,
        ]]);

        $stored = $childRepo->findByChildRunId(self::CHILD_RUN_ID);
        $this->assertNotNull($stored);
        $this->assertSame(self::CHILD_MODEL, $stored->definitionModel);

        $platform = new class implements PlatformInterface {
            public ?string $lastModel = null;

            /** @var list<string> */
            public array $models = [];

            public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
            {
                $this->lastModel = $request->model;
                $this->models[] = $request->model;

                return new PlatformInvocationResult(
                    assistantMessage: new AssistantMessage(new Text('ok')),
                    deltas: [],
                    usage: ['input_tokens' => 1, 'output_tokens' => 1],
                    stopReason: 'stop',
                    error: null,
                    modelNotifications: [],
                );
            }
        };

        $resolver = self::getContainer()->get(RunModelResolverInterface::class);
        \assert($resolver instanceof RunModelResolverInterface);

        $worker = new ExecuteLlmStepWorker(
            platform: $platform,
            commandBus: new TestMessageBus(),
            defaultModel: self::DEFAULT_CODEX_MODEL,
            runModelResolver: $resolver,
        );

        // Current production message has no model field. The RED assertion
        // documents that the durable child model must still reach the platform.
        $worker(new ExecuteLlmStep(
            runId: self::CHILD_RUN_ID,
            turnNo: 620,
            stepId: 'advance-after-tools-model-routing',
            attempt: 1,
            idempotencyKey: 'ik-child-model-routing',
            contextRef: 'hot:run:'.self::CHILD_RUN_ID,
            toolsRef: 'toolset:run:'.self::CHILD_RUN_ID.':turn:620',
        ));

        $this->assertSame(
            self::CHILD_MODEL,
            $platform->lastModel,
            \sprintf(
                'Child definition model %s must be invoked; got %s (default=%s, colliding session 3 model=%s).',
                self::CHILD_MODEL,
                $platform->lastModel ?? 'null',
                self::DEFAULT_CODEX_MODEL,
                self::COLLIDING_SESSION_MODEL,
            ),
        );
        $this->assertNotSame(self::DEFAULT_CODEX_MODEL, $platform->lastModel);
        $this->assertNotSame(self::COLLIDING_SESSION_MODEL, $platform->lastModel);
    }

    protected static function configureIsolatedProjectBeforeKernelBoot(string $classCwd): void
    {
        $settings = [
            'ai' => [
                'default_model' => self::DEFAULT_CODEX_MODEL,
                'default_reasoning' => 'medium',
                'providers' => [
                    'deepseek' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'https://api.deepseek.com',
                        'completions_path' => '/chat/completions',
                        'models' => [
                            'deepseek-v4-flash' => [
                                'id' => 'deepseek-v4-flash',
                                'name' => 'DeepSeek V4 Flash',
                                'context_window' => 1000000,
                                'max_tokens' => 384000,
                                'input' => ['text'],
                                'tool_calling' => true,
                                'reasoning' => true,
                            ],
                            'deepseek-v4-pro' => [
                                'id' => 'deepseek-v4-pro',
                                'name' => 'DeepSeek V4 Pro',
                                'context_window' => 1000000,
                                'max_tokens' => 384000,
                                'input' => ['text'],
                                'tool_calling' => true,
                                'reasoning' => true,
                            ],
                        ],
                    ],
                    'openai-codex' => [
                        'type' => 'codex',
                        'enabled' => true,
                        'base_url' => 'https://chatgpt.com/backend-api',
                        'completions_path' => '/codex/responses',
                        'models' => [
                            'gpt-5.6-sol' => [
                                'id' => 'gpt-5.6-sol',
                                'name' => 'GPT-5.6 Sol',
                                'context_window' => 372000,
                                'max_tokens' => 128000,
                                'input' => ['text', 'image'],
                                'tool_calling' => true,
                                'reasoning' => true,
                            ],
                            'gpt-5.6-luna' => [
                                'id' => 'gpt-5.6-luna',
                                'name' => 'GPT-5.6 Luna',
                                'context_window' => 372000,
                                'max_tokens' => 128000,
                                'input' => ['text', 'image'],
                                'tool_calling' => true,
                                'reasoning' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        file_put_contents(
            $classCwd.'/.hatfield/settings.yaml',
            "# hatfield settings (P0 model-routing regression isolation)\n".Yaml::dump($settings, 6, 2),
        );
    }
}
