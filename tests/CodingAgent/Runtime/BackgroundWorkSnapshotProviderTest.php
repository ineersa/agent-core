<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime;

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Entity\DeferredToolCompletionRepository;
use Ineersa\CodingAgent\Runtime\BackgroundWorkSnapshotProvider;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Uid\Uuid;

final class BackgroundWorkSnapshotProviderTest extends IsolatedKernelTestCase
{
    public function testReadyCandidatesAndBackgroundInventoryRespectOwnership(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $root = $sessions->createSession('inventory');
        $foreign = $sessions->createSession('foreign');
        $child = Uuid::v7()->toRfc4122();
        $container->get(AgentArtifactRegistry::class)->create($root, 'owned', $child, 'scout', AgentArtifactKindEnum::Subagent);
        $repository = $container->get(DeferredToolCompletionRepository::class);
        $ids = [];
        foreach ([$root, $child, $foreign] as $run) {
            $id = $ids[$run] = Uuid::v7()->toRfc4122();
            $correlation = new DeferredToolCompletionCorrelation($id, $run, 1, 'step', 1, 'key', 'host', 'code_mode', [], 0);
            TestToolBatchRegistration::registerDeferredInvocation($container->get(ToolBatchStoreInterface::class), $correlation);
            $repository->registerPending($correlation);
            $repository->markReady($id);
        }
        $provider = $container->get(BackgroundWorkSnapshotProvider::class);
        $snapshot = $provider->snapshot($root);
        $this->assertSame([$ids[$root]], array_column($snapshot->codeMode, 'lifecycleId'));
        $this->assertSame([], $snapshot->work);
        foreach ($ids as $id) {
            $repository->markBackgrounded($id, new \DateTimeImmutable());
        }
        $snapshot = $provider->snapshot($root);
        $this->assertSame([], $snapshot->codeMode);
        $visible = array_column($snapshot->work, 'id');
        $this->assertEqualsCanonicalizing([$ids[$root], $ids[$child]], $visible);
        $this->assertNotContains($ids[$foreign], $visible);
        $this->assertSame([true, true], array_column($snapshot->work, 'stoppable'));
        $repository->markCompleted($ids[$child]);
        $this->assertSame([$ids[$root]], array_column($provider->snapshot($root)->work, 'id'));
    }
}
