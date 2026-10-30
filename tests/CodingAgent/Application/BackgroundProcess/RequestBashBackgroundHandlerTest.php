<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\BackgroundProcess;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RequestBashBackgroundDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\RequestBashBackgroundHandler;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RequestBashBackgroundHandlerTest extends IsolatedKernelTestCase
{
    public function testCommittedIntentReplaysAfterAcceptanceAndCompletionWithoutHydratingEntities(): void
    {
        $container = self::getContainer();
        $runId = $container->get(HatfieldSessionStore::class)->createSession('request bash background');
        $store = $container->get(ProcessStore::class);
        // Only liveness is observed; this handler never signals or launches work.
        $id = $store->insertRecord(['pid' => getmypid(), 'session_id' => $runId, 'tool_call_id' => 'call', 'started_at' => new \DateTimeImmutable()]);
        $call = new ExecuteToolCall($runId, 1, 'step', 1, 'key', 'call', 'bash', ['command' => 'true'], 0);
        $batches = $container->get(ToolBatchStoreInterface::class);
        TestToolBatchRegistration::register(new ToolBatchCollector($batches), $batches, $runId, 1, 'step', [$call]);
        $manager = $container->get(EntityManagerInterface::class);
        $manager->clear();
        $sink = new InMemoryRuntimeEventSink();
        $handler = new RequestBashBackgroundHandler($store, $container->get(ProcessLifecycle::class), $container->get(RunLockManager::class), $container->get(ValidatorInterface::class), $sink);
        $request = new RequestBashBackgroundDTO($runId, $id, 'call');
        for ($i = 0; $i < 32; ++$i) {
            $selected = $store->fetchForegroundTools($runId);
            $this->assertCount(1, $selected);
            $this->assertSame($id, $selected[0]->id);
        }
        $this->assertSame(0, $manager->getUnitOfWork()->size());
        $handler($request);
        $this->assertSame([], $store->fetchForegroundTools($runId));
        $connection = $manager->getConnection();
        $connection->executeStatement("UPDATE background_process SET backgrounded_at = '2026-10-28 00:00:01', finished_at = '2026-10-28 00:00:02' WHERE id = ?", [$id]);
        $handler($request);
        $this->assertSame([], iterator_to_array($sink->drain($runId)));
        $this->assertSame(1, (int) $connection->fetchOne('SELECT background_requested FROM background_process WHERE id = ?', [$id]));
        $this->assertSame(0, $manager->getUnitOfWork()->size());
        $handler(new RequestBashBackgroundDTO('other-run', $id, 'call'));
        $handler(new RequestBashBackgroundDTO($runId, $id, 'other-call'));
        $events = iterator_to_array($sink->drain(''));
        $this->assertCount(2, $events);
        $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $events[0]->type);
    }

    public function testAlreadyFinishedInvocationCannotAcquireBackgroundIntent(): void
    {
        $container = self::getContainer();
        $runId = $container->get(HatfieldSessionStore::class)->createSession('request bash finished');
        $store = $container->get(ProcessStore::class);
        $id = $store->insertRecord(['pid' => getmypid(), 'session_id' => $runId, 'tool_call_id' => 'call', 'started_at' => new \DateTimeImmutable()]);
        $call = new ExecuteToolCall($runId, 1, 'step', 1, 'key', 'call', 'bash', ['command' => 'true'], 0);
        $batches = $container->get(ToolBatchStoreInterface::class);
        TestToolBatchRegistration::register(new ToolBatchCollector($batches), $batches, $runId, 1, 'step', [$call]);
        $connection = $container->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("UPDATE background_process SET finished_at = '2026-10-28 00:00:01' WHERE id = ?", [$id]);
        $sink = new InMemoryRuntimeEventSink();
        $handler = new RequestBashBackgroundHandler($store, $container->get(ProcessLifecycle::class), $container->get(RunLockManager::class), $container->get(ValidatorInterface::class), $sink);
        $handler(new RequestBashBackgroundDTO($runId, $id, 'call'));
        $this->assertCount(1, iterator_to_array($sink->drain($runId)));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT background_requested FROM background_process WHERE id = ?', [$id]));
    }
}
