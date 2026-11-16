<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\Goal;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Session\Goal\GoalContextHook;
use Ineersa\CodingAgent\Session\Goal\SessionGoalService;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class GoalContextHookTest extends IsolatedKernelTestCase
{
    public function testCurrentObjectiveIsRequestLocalAndDisappearsOnClear(): void
    {
        $id = self::getContainer()->get(HatfieldSessionStore::class)->createSession('', 'Initial objective');
        $hook = self::getContainer()->get(GoalContextHook::class);
        $messages = [new AgentMessage('system', [['type' => 'text', 'text' => 'Stable prompt']])];
        $first = $hook->transformContext($messages, runId: $id);
        $this->assertSame($messages[0], $first[0]);
        $this->assertStringContainsString('Initial objective', $first[1]->content[0]['text']);
        $this->assertTrue($first[1]->metadata['system_reminder']);
        $goals = self::getContainer()->get(SessionGoalService::class);
        $goals->set($id, 'Edited after compaction');
        $this->assertStringContainsString('Edited after compaction', $hook->transformContext($messages, runId: $id)[1]->content[0]['text']);
        $goals->set($id, null);
        $this->assertSame($messages, $hook->transformContext($messages, runId: $id));
        $this->assertSame($messages, $hook->transformContext($messages, runId: 'native-child'));
    }
}
