<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactPathResolver;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\ChildAgentEventsPathResolver;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionAgentArtifactPathResolver;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Test thesis: canonical session artifact paths are the single source of truth;
 * AppAgent and runtime adapters must resolve identical absolute events paths.
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
            entityManager: $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function eventsPathMatchesBetweenCanonicalAppAgentAndRuntimeAdapters(): void
    {
        $canonical = new SessionAgentArtifactPathResolver($this->hatfieldSessionStore);
        $appAgent = new AgentArtifactPathResolver($canonical);
        $runtime = new ChildAgentEventsPathResolver($canonical);

        $parent = 'parent-run-abc';
        $artifact = 'artifact-scout-1';

        $expected = $this->projectDir.'/.hatfield/sessions/'.$parent.'/artifacts/agents/'.$artifact.'/events.jsonl';

        $this->assertSame($expected, $canonical->eventsPath($parent, $artifact));
        $this->assertSame($expected, $appAgent->eventsPath($parent, $artifact));
        $this->assertSame($expected, $runtime->eventsPath($parent, $artifact));
    }

    #[Test]
    public function validatePathComponentRejectsTraversal(): void
    {
        $canonical = new SessionAgentArtifactPathResolver($this->hatfieldSessionStore);

        $this->expectException(\InvalidArgumentException::class);
        $canonical->eventsPath('parent', '../evil');
    }
}
