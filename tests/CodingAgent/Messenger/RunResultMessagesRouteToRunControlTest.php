<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Messenger;

use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Test thesis: dispatching run result messages on agent.command.bus must enqueue
 * them on the run_control transport (SendMessageMiddleware) instead of relying on
 * synchronous in-process handler execution inside llm/tool consumer processes.
 *
 * @coversNothing
 */
final class RunResultMessagesRouteToRunControlTest extends IsolatedKernelTestCase
{
    #[DataProvider('runResultMessageProvider')]
    public function testDispatchEnqueuesOnRunControlTransport(object $message): void
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_control');
        $transport->reset();

        /** @var MessageBusInterface $commandBus */
        $commandBus = self::getContainer()->get('agent.command.bus');
        $commandBus->dispatch($message);

        $sent = $transport->getSent();
        $this->assertCount(1, $sent, 'Expected exactly one envelope on run_control after dispatch.');
        $this->assertSame($message, $sent[0]->getMessage());
    }

    /**
     * @return array<string, array{0: object}>
     */
    public static function runResultMessageProvider(): array
    {
        return [
            'LlmStepResult' => [
                new LlmStepResult(
                    runId: 'run-llm-route',
                    turnNo: 1,
                    stepId: 'step-llm',
                    attempt: 1,
                    idempotencyKey: 'ik-llm-route',
                    usage: ['total_tokens' => 1],
                    stopReason: 'end_turn',
                ),
            ],
            'ToolCallResult' => [
                new ToolCallResult(
                    runId: 'run-tool-route',
                    turnNo: 2,
                    stepId: 'step-tool',
                    attempt: 1,
                    idempotencyKey: 'ik-tool-route',
                    toolCallId: 'call-route-1',
                    orderIndex: 0,
                    result: ['ok' => true],
                ),
            ],
            'CompactionStepResult' => [
                new CompactionStepResult(
                    runId: 'run-compact-route',
                    turnNo: 1,
                    stepId: 'step-compact',
                    attempt: 1,
                    idempotencyKey: 'ik-compact-route',
                    summaryText: 'summary',
                    error: null,
                    retainedTailMessages: [],
                    messagesCompacted: 3,
                    messagesRetained: 1,
                    firstRetainedIndex: 2,
                    tokenEstimateBefore: 100,
                    trigger: 'manual',
                ),
            ],
        ];
    }
}
