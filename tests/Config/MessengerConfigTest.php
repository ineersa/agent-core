<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Config;

use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\CollectToolBatch;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ProjectJsonlOutbox;
use Ineersa\AgentCore\Domain\Message\ProjectMercureOutbox;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use PHPUnit\Framework\TestCase;

final class MessengerConfigTest extends TestCase
{
    public function testCommandAndExecutionMessagesRouteToDifferentTransports(): void
    {
        /** @var array<string, array<array<string, mixed>>> $config */
        $config = require dirname(__DIR__, 2).'/config/messenger.php';

        /** @var array<string, mixed> $messenger */
        $messenger = $config['framework'][0]['messenger'];

        self::assertArrayHasKey('agent.command.bus', $messenger['buses']);
        self::assertArrayHasKey('agent.execution.bus', $messenger['buses']);
        self::assertArrayHasKey('agent.publisher.bus', $messenger['buses']);

        /** @var array<class-string, list<string>> $routing */
        $routing = $messenger['routing'];

        self::assertSame(['agent_loop.command'], $routing[StartRun::class]);
        self::assertSame(['agent_loop.command'], $routing[ApplyCommand::class]);
        self::assertSame(['agent_loop.command'], $routing[AdvanceRun::class]);

        self::assertSame(['agent_loop.execution'], $routing[ExecuteLlmStep::class]);
        self::assertSame(['agent_loop.execution'], $routing[ExecuteToolCall::class]);
        self::assertSame(['agent_loop.execution'], $routing[CollectToolBatch::class]);
        self::assertSame(['agent_loop.command'], $routing[LlmStepResult::class]);
        self::assertSame(['agent_loop.command'], $routing[ToolCallResult::class]);

        self::assertSame(['agent_loop.publisher'], $routing[ProjectJsonlOutbox::class]);
        self::assertSame(['agent_loop.publisher'], $routing[ProjectMercureOutbox::class]);

        self::assertNotSame($routing[StartRun::class], $routing[ExecuteLlmStep::class]);
    }
}
