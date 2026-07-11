<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Schema\EventPayloadNormalizer;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStore;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Execution\SubagentChildProgressSummaryBuilder;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

#[CoversClass(SubagentChildProgressSummaryBuilder::class)]
final class SubagentChildProgressSummaryBuilderTest extends IsolatedKernelTestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('hatfield-child-summary');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    public function testSummarizeCountsToolsTokensAndSanitizesToolArgs(): void
    {
        $parentRunId = 'parent-'.bin2hex(random_bytes(4));
        $childRunId = 'child-'.bin2hex(random_bytes(4));
        $artifactId = 'agent_'.bin2hex(random_bytes(4));

        $eventStore = $this->createChildEventStore($parentRunId, $childRunId, $artifactId);
        $events = [
            new RunEvent($childRunId, 1, 0, RunEventTypeEnum::RunStarted->value, [
                'step_id' => 's0',
                'payload' => ['metadata' => [
                    'model' => 'deepseek/deepseek-v4-flash',
                    'context_window' => \Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::CONTEXT_WINDOW,
                ]],
            ]),
            new RunEvent($childRunId, 2, 1, RunEventTypeEnum::LlmStepCompleted->value, [
                'step_id' => 's1',
                'usage' => ['input_tokens' => 10000, 'output_tokens' => 4000, 'thinking_tokens' => 200000, 'total_tokens' => 214000, 'cost' => 0.004],
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Inspecting files.']],
                    'tool_calls' => [[
                        'id' => 'tc_read',
                        'name' => 'read',
                        'arguments' => ['path' => 'src/Tui/Transcript/ChatScreen.php'],
                    ]],
                ],
            ]),
            new RunEvent($childRunId, 3, 1, RunEventTypeEnum::ToolExecutionEnd->value, [
                'tool_call_id' => 'tc_read',
                'tool_name' => 'read',
            ]),
            new RunEvent($childRunId, 4, 2, RunEventTypeEnum::LlmStepCompleted->value, [
                'step_id' => 's2',
                'usage' => ['input_tokens' => 25000, 'output_tokens' => 10000, 'thinking_tokens' => 384000, 'total_tokens' => 419000, 'cost' => 0.0064],
                'assistant_message' => [
                    'role' => 'assistant',
                    'content' => [['type' => 'text', 'text' => 'Next step.']],
                    'tool_calls' => [[
                        'id' => 'tc_bash',
                        'name' => 'bash',
                        'arguments' => ['command' => 'grep -n Subagent src/Tui'],
                    ]],
                ],
            ]),
            new RunEvent($childRunId, 5, 2, RunEventTypeEnum::ToolExecutionEnd->value, [
                'tool_call_id' => 'tc_bash',
                'tool_name' => 'bash',
            ]),
        ];
        foreach ($events as $event) {
            $eventStore->append($event);
        }

        $childState = new RunState(
            runId: $childRunId,
            status: RunStatus::Running,
            version: 3,
            turnNo: 3,
            lastSeq: 5,
            messages: [
                new AgentMessage('assistant', [['type' => 'text', 'text' => 'Found the rendering path in ChatScreen.']]),
            ],
        );

        $pathResolver = new AgentArtifactPathResolver(new SessionAgentArtifactPathResolver(new HatfieldSessionStore(
            appConfig: new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig(), cwd: $this->projectDir),
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        )));
        $factory = new AgentChildRunEventStoreFactory(
            $pathResolver,
            new EventPayloadNormalizer(),
            new LockFactory(new FlockStore()),
            new NullLogger(),
        );
        $builder = new SubagentChildProgressSummaryBuilder($factory);
        $summary = $builder->summarize($parentRunId, $childRunId, $artifactId, $childState, 'deepseek/deepseek-v4-flash');

        $this->assertSame(2, $summary->toolCount);
        $this->assertSame(35000, $summary->inputTokens);
        $fields = $summary->toProgressFields();
        $this->assertSame(35000, $fields['input_tokens']);
        $this->assertSame(25000, $fields['latest_input_tokens'] ?? null, 'Latest LLM step input_tokens must be exposed separately from cumulative input_tokens');
        $this->assertSame('deepseek/deepseek-v4-flash', $fields['model'] ?? null);
        $this->assertSame(
            \Ineersa\Tui\Tests\Support\ChildContextStatisticsFixture::CONTEXT_WINDOW,
            $fields['context_window'] ?? null,
            'Canonical context_window from child run metadata must propagate into progress fields',
        );
        $this->assertSame(14000, $summary->outputTokens);
        $this->assertSame(584000, $summary->reasoningTokens);
        $this->assertSame(0.0104, $summary->cost);
        $this->assertStringContainsString('artifacts/agents/'.$artifactId, (string) $summary->artifactPath);
        $this->assertStringContainsString('Next step', (string) $summary->assistantExcerpt);
        $this->assertNotEmpty($summary->recentTools);
        $this->assertStringContainsString('read:', $summary->recentTools[0]);
        $this->assertStringContainsString('path="', $summary->recentTools[0]);
        $this->assertStringNotContainsString('tool end', implode(' ', $summary->recentTools));
        $this->assertStringContainsString('grep', implode(' ', $summary->recentTools));
        $this->assertNull($summary->activeToolLine);
    }

    private function createChildEventStore(string $parentRunId, string $childRunId, string $artifactId): AgentChildRunEventStore
    {
        $hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->projectDir,
            ),
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );

        return new AgentChildRunEventStore(
            pathResolver: new AgentArtifactPathResolver(new SessionAgentArtifactPathResolver($hatfieldSessionStore)),
            eventPayloadNormalizer: new EventPayloadNormalizer(),
            lockFactory: new LockFactory(new FlockStore()),
            logger: new NullLogger(),
            parentRunId: $parentRunId,
            agentRunId: $childRunId,
            artifactId: $artifactId,
        );
    }
}
