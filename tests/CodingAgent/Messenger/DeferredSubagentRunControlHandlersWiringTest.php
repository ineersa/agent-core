<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Messenger;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch\ContinueForkDeferredPrelaunchHandler;
use Ineersa\CodingAgent\Agent\Execution\Fork\Batch\Deferred\Prelaunch\ContinueForkDeferredPrelaunchMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Interruption\InterruptDeferredSubagentBatchHandler;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Interruption\InterruptDeferredSubagentBatchMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeliverDeferredSubagentBatchLifecycleHandler;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Lifecycle\DeliverDeferredSubagentBatchLifecycleMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation\ObserveDeferredSubagentBatchChildTurnHandler;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Observation\ObserveDeferredSubagentBatchChildTurnMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Recovery\RecoverDeferredSubagentBatchLifecycleHandler;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Recovery\RecoverDeferredSubagentBatchLifecycleMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\Deferred\DeferredSubagentInterruptionKindEnum;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlerDescriptor;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;

/**
 * Test thesis: normalized deferred batch run_control messages must resolve at least one
 * Messenger handler on agent.command.bus in the compiled container.
 *
 * @coversNothing
 */
final class DeferredSubagentRunControlHandlersWiringTest extends IsolatedKernelTestCase
{
    #[DataProvider('runControlMessageProvider')]
    public function testAgentCommandBusHandlersLocatorResolvesHandler(
        object $message,
        string $expectedHandlerClass,
    ): void {
        /** @var HandlersLocatorInterface $handlersLocator */
        $handlersLocator = self::getContainer()->get('agent.command.bus.messenger.handlers_locator');

        $handlers = iterator_to_array($handlersLocator->getHandlers(new Envelope($message)));
        $this->assertNotEmpty($handlers, 'Expected at least one handler for '.$message::class);

        $matched = false;
        foreach ($handlers as $descriptor) {
            $this->assertInstanceOf(HandlerDescriptor::class, $descriptor);
            $this->assertIsCallable($descriptor->getHandler());
            if (str_starts_with($descriptor->getName(), $expectedHandlerClass.'::')) {
                $matched = true;
                break;
            }
        }

        $this->assertTrue(
            $matched,
            \sprintf(
                'Expected a handler descriptor named %s::__invoke among %d descriptor(s) for %s.',
                $expectedHandlerClass,
                \count($handlers),
                $message::class,
            ),
        );
    }

    /**
     * @return array<string, array{0: object, 1: class-string}>
     */
    public static function runControlMessageProvider(): array
    {
        return [
            'ObserveDeferredSubagentBatchChildTurnMessage' => [
                new ObserveDeferredSubagentBatchChildTurnMessage(
                    batchLifecycleId: 'batch-wiring-observe',
                    batchIndex: 1,
                    childRunId: 'child-run-batch-wiring',
                    committedStatus: RunStatus::Running,
                    turnNo: 0,
                    committedEvents: [],
                ),
                ObserveDeferredSubagentBatchChildTurnHandler::class,
            ],
            'DeliverDeferredSubagentBatchLifecycleMessage' => [
                new DeliverDeferredSubagentBatchLifecycleMessage(
                    batchLifecycleId: 'batch-wiring-deliver',
                ),
                DeliverDeferredSubagentBatchLifecycleHandler::class,
            ],
            'InterruptDeferredSubagentBatchMessage' => [
                new InterruptDeferredSubagentBatchMessage(
                    batchLifecycleId: 'batch-wiring-interrupt',
                    kind: DeferredSubagentInterruptionKindEnum::Timeout,
                ),
                InterruptDeferredSubagentBatchHandler::class,
            ],
            'RecoverDeferredSubagentBatchLifecycleMessage' => [
                new RecoverDeferredSubagentBatchLifecycleMessage(
                    batchLifecycleId: 'batch-wiring-recover',
                ),
                RecoverDeferredSubagentBatchLifecycleHandler::class,
            ],
            'ContinueForkDeferredPrelaunchMessage' => [
                new ContinueForkDeferredPrelaunchMessage(
                    batchLifecycleId: 'batch-wiring-fork-prelaunch',
                    forkLocalRunId: 'fork-local-wiring',
                    terminalEventType: 'context_compacted',
                ),
                ContinueForkDeferredPrelaunchHandler::class,
            ],
        ];
    }
}
