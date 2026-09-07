<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobDispatcher;
use Ineersa\CodingAgent\Extension\Agent\ExtensionAgentJobMessage;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Runtime\Controller\ExtensionSessionStartHookSubscriber;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Hatfield\ExtensionApi\Agent\ExtensionAgentJobRequestDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookContextDTO;
use Ineersa\Hatfield\ExtensionApi\Lifecycle\AfterSessionStartHookInterface;
use Ineersa\HatfieldExt\Jbcontext\Job\JbcontextEligibilityJobHandler;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Thesis: controller session start fires public session-start hooks, and hooks
 * that dispatch through the real ExtensionAgentJobDispatcher enqueue on the
 * async extension_agent transport before any user turn.
 */
final class ExtensionSessionStartHookSubscriberTest extends IsolatedKernelTestCase
{
    #[Test]
    public function controllerSessionStartDispatchesRegisteredHooks(): void
    {
        $registry = new ExtensionHookRegistry();
        $seen = [];
        $registry->addSessionStartHook(new class($seen) implements AfterSessionStartHookInterface {
            /** @param list<string> $seen */
            public function __construct(private array &$seen)
            {
            }

            public function onAfterSessionStart(AfterSessionStartHookContextDTO $context): void
            {
                $this->seen[] = $context->runId;
            }
        });

        $logger = new TestLogger();
        $subscriber = new ExtensionSessionStartHookSubscriber($registry, $logger);
        $dispatcher = new EventDispatcher();
        $dispatcher->addListener(ControllerSessionStartingEvent::class, $subscriber->onSessionStarting(...));
        $dispatcher->dispatch(new ControllerSessionStartingEvent('session-start-1'));

        $this->assertSame(['session-start-1'], $seen);
    }

    #[Test]
    public function sessionStartHookCanEnqueueExtensionAgentJobOnRealTransport(): void
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.extension_agent');
        $transport->reset();

        /** @var MessageBusInterface $executionBus */
        $executionBus = self::getContainer()->get('agent.execution.bus');
        $dispatcher = new ExtensionAgentJobDispatcher($executionBus, new TestLogger(), 'in-memory://');

        $registry = new ExtensionHookRegistry();
        $registry->addSessionStartHook(new class($dispatcher) implements AfterSessionStartHookInterface {
            public function __construct(private ExtensionAgentJobDispatcher $dispatcher)
            {
            }

            public function onAfterSessionStart(AfterSessionStartHookContextDTO $context): void
            {
                $this->dispatcher->dispatch(new ExtensionAgentJobRequestDTO(
                    handlerId: JbcontextEligibilityJobHandler::HANDLER_ID,
                    payload: [
                        'session_id' => $context->runId,
                        'attempt' => 1,
                    ],
                    jobId: 'jbcontext.eligibility.'.$context->runId.'.attempt.1',
                    correlationId: $context->runId,
                ));
            }
        });

        $subscriber = new ExtensionSessionStartHookSubscriber($registry, new TestLogger());
        $subscriber->onSessionStarting(new ControllerSessionStartingEvent('session-live-1'));

        $sent = $transport->getSent();
        $this->assertCount(1, $sent, 'Expected eligibility job on extension_agent before any turn.');
        $message = $sent[0]->getMessage();
        $this->assertInstanceOf(ExtensionAgentJobMessage::class, $message);
        $this->assertSame(JbcontextEligibilityJobHandler::HANDLER_ID, $message->handlerId);
        $this->assertSame('session-live-1', $message->payload['session_id'] ?? null);
        $this->assertSame('jbcontext.eligibility.session-live-1.attempt.1', $message->jobId);
    }
}
