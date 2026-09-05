<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\Jbcontext\Assets\JbcontextAssetInstaller;
use Ineersa\HatfieldExt\Jbcontext\Assets\JbcontextManagedMarker;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextAssetInstallerTest extends TestCase
{
    private string $projectDir;
    private string $packageRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('jbcontext-assets-');
        $this->packageRoot = \dirname(__DIR__);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function installsManagedSkillAndScoutWhenAbsent(): void
    {
        $logger = new TestLogger();
        $installer = new JbcontextAssetInstaller(
            JbcontextPaths::fromProjectRoot($this->projectDir),
            $this->packageRoot,
            $logger,
        );

        $installer->install();

        $skill = $this->projectDir.'/.hatfield/skills/jbcontext-semantic-search/SKILL.md';
        $scout = $this->projectDir.'/.hatfield/agents/scout.md';
        $this->assertFileExists($skill);
        $this->assertFileExists($scout);
        $this->assertTrue(JbcontextManagedMarker::isManaged((string) file_get_contents($skill)));
        $this->assertTrue(JbcontextManagedMarker::isManaged((string) file_get_contents($scout)));
    }

    #[Test]
    public function doesNotOverwriteUserOwnedScout(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        mkdir(\dirname($paths->scoutDestinationPath), 0o777, true);
        file_put_contents($paths->scoutDestinationPath, "---\nname: scout\n---\nuser owned\n");

        $logger = new TestLogger();
        (new JbcontextAssetInstaller($paths, $this->packageRoot, $logger))->install();

        $this->assertSame("---\nname: scout\n---\nuser owned\n", file_get_contents($paths->scoutDestinationPath));
        $events = array_map(
            static fn (array $r): string => (string) ($r['context']['event_type'] ?? $r['message']),
            $logger->records,
        );
        $this->assertContains('jbcontext.assets.scout_collision', $events);
    }
}
