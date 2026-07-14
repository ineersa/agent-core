<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Messenger;

use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeliverDeferredSubagentBatchLifecycleHandler;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\DeliverDeferredSubagentBatchLifecycleMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\InterruptDeferredSubagentBatchHandler;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\InterruptDeferredSubagentBatchMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\ObserveDeferredSubagentBatchChildTurnHandler;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\ObserveDeferredSubagentBatchChildTurnMessage;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\RecoverDeferredSubagentBatchLifecycleHandler;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\RecoverDeferredSubagentBatchLifecycleMessage;
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
        ];
    }
}
