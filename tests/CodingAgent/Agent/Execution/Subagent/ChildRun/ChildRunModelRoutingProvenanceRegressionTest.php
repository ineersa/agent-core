<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun;

use Ineersa\AgentCore\Application\Handler\ExecuteLlmStepWorker;
use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\Model\PlatformInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\PlatformInvocationResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\Component\Yaml\Yaml;

/**
 * P0 cost-safety regression for session-33 model drift.
 *
 * Thesis: a child run whose run_started metadata.model is DeepSeek must schedule
 * and invoke DeepSeek even when its UUID numeric prefix collides with a normal
 * session whose model/default is Codex. Scheduling uses only RunState.model.
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
        $this->assertSame('3', $collidingSessionId);
        $this->assertSame(3, (int) self::CHILD_RUN_ID);

        $runStarted = new RunEvent(
            runId: self::CHILD_RUN_ID,
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'step_id' => 'start-child',
                'payload' => [
                    'messages' => [],
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'parent_run_id' => '1',
                        ],
                        'model' => self::CHILD_MODEL,
                    ],
                ],
            ],
        );

        $reducer = new RunStateReducer();
        $state = $reducer->replay(RunState::queued(self::CHILD_RUN_ID), [$runStarted]);
        $this->assertSame(self::CHILD_MODEL, $state->model);

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: self::getContainer()->get(CommandMailboxPolicy::class),
            eventFactory: self::getContainer()->get(EventFactory::class),
        );
        $result = $handler->handle(
            new AdvanceRun(
                runId: self::CHILD_RUN_ID,
                turnNo: 0,
                stepId: 'advance-1',
                attempt: 1,
                idempotencyKey: 'ik-advance-1',
            ),
            $state,
        );

        $effect = null;
        foreach ($result->effects as $candidate) {
            if ($candidate instanceof ExecuteLlmStep) {
                $effect = $candidate;
                break;
            }
        }
        $this->assertInstanceOf(ExecuteLlmStep::class, $effect);
        $this->assertSame(self::CHILD_MODEL, $effect->model);

        $platform = new class implements PlatformInterface {
            public ?string $lastModel = null;

            public function invoke(ModelInvocationRequest $request): PlatformInvocationResult
            {
                $this->lastModel = $request->model;

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

        $worker = new ExecuteLlmStepWorker(
            platform: $platform,
            commandBus: new TestMessageBus(),
        );
        $worker($effect);

        $this->assertSame(self::CHILD_MODEL, $platform->lastModel);
        $this->assertNotSame(self::DEFAULT_CODEX_MODEL, $platform->lastModel);
        $this->assertNotSame(self::COLLIDING_SESSION_MODEL, $platform->lastModel);
    }

    public function testMissingCanonicalModelFailsClosedBeforeScheduling(): void
    {
        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: self::getContainer()->get(CommandMailboxPolicy::class),
            eventFactory: self::getContainer()->get(EventFactory::class),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('run model is absent');

        $handler->handle(
            new AdvanceRun(
                runId: 'missing-model-run',
                turnNo: 0,
                stepId: 'advance-missing',
                attempt: 1,
                idempotencyKey: 'ik-missing',
            ),
            new RunState(
                runId: 'missing-model-run',
                status: RunStatus::Running,
                model: null,
            ),
        );
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
