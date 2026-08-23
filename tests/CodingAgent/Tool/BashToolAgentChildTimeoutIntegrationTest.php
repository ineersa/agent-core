<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Entity\ToolQuestion;
use Ineersa\CodingAgent\Entity\ToolQuestionStatusEnum;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\Arguments\BashArgumentsDTO;
use Ineersa\CodingAgent\Tool\BashTool;
use Ineersa\CodingAgent\Tool\ToolQuestion\ToolQuestionStoreInterface;

/**
 * Production DI proof: agent_child Bash never creates a background ToolQuestion,
 * and the requested Bash timeout still resolves through the wired
 * RuntimeBashBackgroundPromptAdapter path.
 *
 * @requires extension pdo_sqlite
 * @requires OS Linux
 */
final class BashToolAgentChildTimeoutIntegrationTest extends IsolatedKernelTestCase
{
    public function testAgentChildTimeoutResolvesWithoutToolQuestion(): void
    {
        $childRunId = 'agent-child-bash-di-'.bin2hex(random_bytes(4));

        /** @var EventStoreInterface $eventStore */
        $eventStore = self::getContainer()->get(EventStoreInterface::class);
        $eventStore->append(new RunEvent(
            runId: $childRunId,
            seq: 0,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'step_id' => 'start-1',
                'payload' => [
                    'system_prompt' => 'You are a scout.',
                    'messages' => [],
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'parent_run_id' => 'parent-di',
                            'agent_name' => 'scout',
                            'artifact_id' => 'agent_di123',
                            'interactive' => false,
                        ],
                        'model' => 'deepseek/deepseek-v4-flash',
                        'reasoning' => 'medium',
                        'tools_scope' => [
                            'allowed_tools' => ['bash'],
                            'mcp' => [
                                'mode' => 'none',
                                'tools' => [],
                            ],
                        ],
                        'extensions' => [],
                    ],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        ));

        /** @var BashTool $bashTool */
        $bashTool = self::getContainer()->get(BashTool::class);
        /** @var StackToolExecutionContextAccessor $accessor */
        $accessor = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        /** @var ToolQuestionStoreInterface $questionStore */
        $questionStore = self::getContainer()->get(ToolQuestionStoreInterface::class);

        $cancelToken = $this->createStub(CancellationTokenInterface::class);
        $cancelToken->method('isCancellationRequested')->willReturn(false);

        $context = new ToolContext(
            runId: $childRunId,
            turnNo: 1,
            toolCallId: 'tc_child_bash_di',
            toolName: 'bash',
            cancellationToken: $cancelToken,
            // Ambient policy timeout stays null/large; BashArgumentsDTO timeout is authoritative.
            timeoutSeconds: null,
        );

        $started = hrtime(true);
        $result = $accessor->with($context, static fn (): string => $bashTool(new BashArgumentsDTO(
            command: 'echo "di-partial" && sleep 10 && echo "should-not-see"',
            timeout: 1,
        )));
        $elapsedMs = (int) round((hrtime(true) - $started) / 1_000_000);

        $this->assertStringContainsString('Command timed out after 1 seconds', $result);
        $this->assertStringContainsString('di-partial', $result);
        $this->assertStringNotContainsString('should-not-see', $result);
        $this->assertStringNotContainsString('Command moved to background', $result);
        $this->assertGreaterThanOrEqual(900, $elapsedMs);
        $this->assertLessThan(4000, $elapsedMs, 'DI child bash must honor BashArgumentsDTO timeout without HITL wait');

        $pending = array_values(array_filter(
            $questionStore->findUnemittedPendingQuestions(),
            static fn (ToolQuestion $q): bool => $q->runId === $childRunId
                && ToolQuestionStatusEnum::Pending === $q->status,
        ));
        $this->assertSame([], $pending, 'agent_child must never create a background ToolQuestion');
    }
}
