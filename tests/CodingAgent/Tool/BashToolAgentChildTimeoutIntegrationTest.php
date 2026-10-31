<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\Arguments\BashArgumentsDTO;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BashTool;

/**
 * Production DI proof: child Bash stays foreground and honors its requested timeout.
 *
 * @requires extension pdo_sqlite
 * @requires OS Linux
 */
final class BashToolAgentChildTimeoutIntegrationTest extends IsolatedKernelTestCase
{
    public function testAgentChildForegroundTimeoutResolves(): void
    {
        $childRunId = 'agent-child-bash-di-'.bin2hex(random_bytes(4));

        /** @var EventStoreInterface $eventStore */
        $eventStore = self::getContainer()->get(EventStoreInterface::class);
        PreparedEventStoreSeeder::append($eventStore, new RunEvent(
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
            stepId: 'child-step',
        );

        $command = 'echo "di-partial" && sleep 10 && echo "should-not-see"';
        self::getContainer()->get(BackgroundProcessManager::class)->prepareNativeLaunch($childRunId, 'tc_child_bash_di', $command);
        $result = $accessor->with($context, static fn () => $bashTool(new BashArgumentsDTO(
            command: $command,
            timeout: 1,
        )));
        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertTrue($result->isError);
        $text = $result->content[0]['text'];
        $this->assertStringContainsString('Command timed out after 1 seconds', $text);
        $this->assertStringContainsString('di-partial', $text);
        $this->assertStringNotContainsString('should-not-see', $text);
        $this->assertStringNotContainsString('Command moved to background', $text);
    }
}
