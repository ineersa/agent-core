<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
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
        $result = $context->with(new ToolContext($id, 1, 'goal-call', 'clear_goal', $token, 30), static fn () => $tool());
        $this->assertSame(['goal' => null], $result);
        $this->assertNull(self::getContainer()->get(SessionGoalService::class)->get($id));
        $this->expectException(GoalAccessException::class);
        $context->with(new ToolContext('native-child', 1, 'child-call', 'clear_goal', $token, 30), static fn () => $tool());
    }
}
