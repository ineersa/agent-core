<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\CodingAgent\Session\Goal\DTO\GoalObjectiveDTO;
use Ineersa\CodingAgent\Session\Goal\Exception\GoalAccessException;
use Ineersa\CodingAgent\Session\Goal\SessionGoalService;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\ClearGoalTool;
use Ineersa\CodingAgent\Tool\ToolRegistry;

final class ClearGoalToolTest extends IsolatedKernelTestCase
{
    public function testNativeToolUsesOwningSessionAndRetainsMainOnlyRegistration(): void
    {
        $id = self::getContainer()->get(HatfieldSessionStore::class)->createSession('', 'Original objective');
        $tool = self::getContainer()->get(ClearGoalTool::class);
        $registry = new ToolRegistry([$tool]);
        $this->assertTrue($registry->toolDefinition('clear_goal')->mainOnly);
        $context = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $token = $this->createStub(CancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturn(false);
        $result = $context->with(new ToolContext($id, 1, 'goal-call', 'clear_goal', $token, 30), static fn () => $tool(new GoalObjectiveDTO('Original objective')));
        $this->assertSame(['cleared' => true, 'goal' => null], $result);
        $this->assertNull(self::getContainer()->get(SessionGoalService::class)->get($id));
        $this->expectException(GoalAccessException::class);
        $context->with(new ToolContext('native-child', 1, 'child-call', 'clear_goal', $token, 30), static fn () => $tool(new GoalObjectiveDTO('Original objective')));
    }

    public function testStaleCompletionPreservesUserReplacementAndReportsCurrentGoal(): void
    {
        $id = self::getContainer()->get(HatfieldSessionStore::class)->createSession('', 'Goal A');
        $goals = self::getContainer()->get(SessionGoalService::class);
        $expected = $goals->get($id);
        $goals->set($id, 'Goal B');
        $context = self::getContainer()->get(StackToolExecutionContextAccessor::class);
        $token = $this->createStub(CancellationTokenInterface::class);
        $token->method('isCancellationRequested')->willReturn(false);
        $call = new ToolContext($id, 1, 'stale-clear', 'clear_goal', $token, 30);
        $tool = self::getContainer()->get(ClearGoalTool::class);
        $this->assertSame(['cleared' => false, 'goal' => 'Goal B'], $context->with($call, static fn () => $tool(new GoalObjectiveDTO($expected))));
        $this->assertSame('Goal B', $goals->get($id));
        $this->assertSame(['cleared' => true, 'goal' => null], $context->with($call, static fn () => $tool(new GoalObjectiveDTO('Goal B'))));
        $this->assertSame(['cleared' => false, 'goal' => null], $context->with($call, static fn () => $tool(new GoalObjectiveDTO('Goal B'))));
    }
}
