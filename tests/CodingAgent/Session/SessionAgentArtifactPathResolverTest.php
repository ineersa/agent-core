<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Runtime\Contract\ChildAgentEventsPathResolverInterface;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/**
 * The shared session resolver fulfills the picker contract for canonical child events paths.
 */
final class SessionAgentArtifactPathResolverTest extends TestCase
{
    private string $projectDir;

    private HatfieldSessionStore $hatfieldSessionStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectDir = TestDirectoryIsolation::createOsTempDir('hatfield-session-artifact-paths');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);

        $this->hatfieldSessionStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->projectDir,
            ),
            entityManager: $this->createStub(EntityManagerInterface::class),
            dispatcher: new EventDispatcher(), lockFactory: new LockFactory(new FlockStore()));
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function resolvesCanonicalChildEventsPathThroughRuntimeContract(): void
    {
        $runtime = new SessionAgentArtifactPathResolver($this->hatfieldSessionStore);
        $this->assertInstanceOf(ChildAgentEventsPathResolverInterface::class, $runtime);

        $parent = 'parent-run-abc';
        $artifact = 'artifact-scout-1';

        $expected = $this->projectDir.'/.hatfield/sessions/'.$parent.'/artifacts/agents/'.$artifact.'/events.jsonl';

        $this->assertSame($expected, $runtime->eventsPath(parentSessionId: $parent, artifactId: $artifact));
    }

    #[Test]
    public function validatePathComponentRejectsTraversal(): void
    {
        $canonical = new SessionAgentArtifactPathResolver($this->hatfieldSessionStore);

        $this->expectException(\InvalidArgumentException::class);
        $canonical->eventsPath('parent', '../evil');
    }
}
