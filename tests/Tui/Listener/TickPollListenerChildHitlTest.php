<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use PHPUnit\Framework\TestCase;

final class TickPollListenerChildHitlTest extends TestCase
{
    public function testChildHumanInputEnqueuesRunScopedRequestAndAnswersChildRun(): void
    {
        $childRunId = 'child-run-hitl-1';
        $sent = null;
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('send')->willReturnCallback(
            static function (string $runId, UserCommand $cmd) use (&$sent, $childRunId): void {
                $sent = [$runId, $cmd];
                self::assertSame($childRunId, $runId);
            },
        );

        $coordinator = new QuestionCoordinator();
        $state = new \Ineersa\Tui\Runtime\TuiSessionState('parent-1');
        $state->subagentLiveView->enter(new SubagentLiveChildDTO(
            $childRunId,
            'agent_a',
            'scout',
            SubagentLiveStatusEnum::WaitingHuman,
            'task',
            1,
            'deepseek/deepseek-v4-flash',
            'medium'));

        $ref = new \ReflectionMethod(RuntimeQuestionEventHandler::class, 'handleHumanInputRequested');
        $event = new RuntimeEvent(
            type: RuntimeEventTypeEnum::HumanInputRequested->value,
            runId: $childRunId,
            seq: 3,
            payload: [
                'question_id' => 'q_child_1',
                'ui_kind' => 'confirm',
                'prompt' => 'Proceed?',
                'schema' => ['type' => 'boolean'],
            ],
        );
        $ref->invoke($this->runtimeQuestionHandler(), $event, $client, $coordinator, $state);

        $active = $coordinator->activeRequest();
        $this->assertNotNull($active);
        $this->assertSame($childRunId, $active->runId);
        $this->assertSame('Subagent scout asks', $active->header);

        $coordinator->answer('yes');
        $this->assertNotNull($sent);
        $this->assertSame('answer_human', $sent[1]->type);
        $this->assertSame('q_child_1', $sent[1]->payload['question_id'] ?? null);
        $this->assertTrue($sent[1]->payload['answer'] ?? false);
    }

    private function runtimeQuestionHandler(): RuntimeQuestionEventHandler
    {
        return new RuntimeQuestionEventHandler();
    }
}
