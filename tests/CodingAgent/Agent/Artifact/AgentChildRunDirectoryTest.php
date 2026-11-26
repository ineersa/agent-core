<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\ChildRunFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class AgentChildRunDirectoryTest extends IsolatedKernelTestCase
{
    public function testLocatesRootAndNestedChildrenThroughTheirBatchParent(): void
    {
        $container = self::getContainer();
        $root = $container->get(HatfieldSessionStore::class)->createSession('root');
        ChildRunFixture::create($container, $root, 'agent_child', 'child-run');
        ChildRunFixture::create($container, 'child-run', 'agent_grandchild', 'grandchild-run');

        $directory = $this->directory();

        $this->assertSame('agent_child', $directory->locate('child-run')?->artifactId);
        $this->assertSame('agent_grandchild', $directory->locate('grandchild-run')?->artifactId);
    }

    public function testParentsAndUnknownRunsAreNotChildren(): void
    {
        $root = self::getContainer()->get(HatfieldSessionStore::class)->createSession('root');
        $directory = $this->directory();

        $this->assertNull($directory->locate($root));
        $this->assertNull($directory->locate('unknown-run'));
    }

    private function directory(): AgentChildRunDirectory
    {
        $container = self::getContainer();

        return new AgentChildRunDirectory($container->get(DeferredSubagentChildRepository::class), $container->get(AgentArtifactRegistry::class));
    }
}
