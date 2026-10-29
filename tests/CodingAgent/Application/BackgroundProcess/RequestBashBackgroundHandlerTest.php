<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\BackgroundProcess;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\CodingAgent\Application\BackgroundProcess\DTO\RequestBashBackgroundDTO;
use Ineersa\CodingAgent\Application\BackgroundProcess\RequestBashBackgroundHandler;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class RequestBashBackgroundHandlerTest extends IsolatedKernelTestCase
{
    public function testCommittedIntentReplaysAfterAcceptanceAndCompletionWithoutHydratingEntities(): void
    {
        $container = self::getContainer();
        $store = $container->get(ProcessStore::class);
        // Only liveness is observed; this handler never signals or launches work.
        $id = $store->insertRecord(['pid' => getmypid(), 'session_id' => 'request-owner', 'tool_call_id' => 'call', 'started_at' => new \DateTimeImmutable()]);
        $manager = $container->get(EntityManagerInterface::class);
        $manager->clear();
        $sink = new InMemoryRuntimeEventSink();
        $handler = new RequestBashBackgroundHandler($store, $container->get(ProcessLifecycle::class), $container->get(RunLockManager::class), $container->get(ValidatorInterface::class), $sink);
        $request = new RequestBashBackgroundDTO('request-owner', $id, 'call');
        for ($i = 0; $i < 32; ++$i) {
            $selected = $store->fetchForegroundTools('request-owner');
            $this->assertCount(1, $selected);
            $this->assertSame($id, $selected[0]->id);
        }
        $this->assertSame(0, $manager->getUnitOfWork()->size());
        $handler($request);
        $this->assertSame([], $store->fetchForegroundTools('request-owner'));
        $connection = $manager->getConnection();
        $connection->executeStatement("UPDATE background_process SET backgrounded_at = '2026-10-28 00:00:01', finished_at = '2026-10-28 00:00:02' WHERE id = ?", [$id]);
        $handler($request);
        $this->assertSame([], iterator_to_array($sink->drain('request-owner')));
        $this->assertSame(1, (int) $connection->fetchOne('SELECT background_requested FROM background_process WHERE id = ?', [$id]));
        $this->assertSame(0, $manager->getUnitOfWork()->size());
        $handler(new RequestBashBackgroundDTO('other-run', $id, 'call'));
        $handler(new RequestBashBackgroundDTO('request-owner', $id, 'other-call'));
        $events = iterator_to_array($sink->drain(''));
        $this->assertCount(2, $events);
        $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $events[0]->type);
    }

    public function testAlreadyFinishedInvocationCannotAcquireBackgroundIntent(): void
    {
        $container = self::getContainer();
        $store = $container->get(ProcessStore::class);
        $id = $store->insertRecord(['pid' => getmypid(), 'session_id' => 'request-owner', 'tool_call_id' => 'call', 'started_at' => new \DateTimeImmutable()]);
        $connection = $container->get(EntityManagerInterface::class)->getConnection();
        $connection->executeStatement("UPDATE background_process SET finished_at = '2026-10-28 00:00:01' WHERE id = ?", [$id]);
        $sink = new InMemoryRuntimeEventSink();
        $handler = new RequestBashBackgroundHandler($store, $container->get(ProcessLifecycle::class), $container->get(RunLockManager::class), $container->get(ValidatorInterface::class), $sink);
        $handler(new RequestBashBackgroundDTO('request-owner', $id, 'call'));
        $this->assertCount(1, iterator_to_array($sink->drain('request-owner')));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT background_requested FROM background_process WHERE id = ?', [$id]));
    }
}
