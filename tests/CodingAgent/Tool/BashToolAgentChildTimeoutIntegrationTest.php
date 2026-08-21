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
 * Production DI proof: agent_child Bash uses the wired RuntimeBashBackgroundPromptAdapter
 * path but never creates a ToolQuestion, and the requested Bash timeout still resolves.
 *
 * @requires extension pdo_sqlite
 * @requires OS Linux
 */
final class BashToolAgentChildTimeoutIntegrationTest extends IsolatedKernelTestCase
{
    public function testAgentChildBashTimeoutResolvesWithoutToolQuestion(): void
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
                'step_id' => 'child-start',
                'payload' => [
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'child_kind' => 'fork',
                            'parent_run_id' => 'parent-run',
                            'agent_name' => 'fork',
                            'artifact_id' => 'agent_bash_di',
                            'interactive' => true,
                        ],
                        'model' => 'llama_cpp_test/test',
                        'reasoning' => 'off',
                        'tools_scope' => [
                            'allowed_tools' => ['bash'],
                            'mcp' => ['mode' => 'none', 'tools' => []],
                        ],
                        'extensions' => [],
                    ],
                ],
            ],
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
        $this->assertSame([], $pending, 'agent_child bash must never create a background ToolQuestion');
    }
}
