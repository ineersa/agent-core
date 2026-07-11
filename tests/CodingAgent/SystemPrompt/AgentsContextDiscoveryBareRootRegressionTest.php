<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\SystemPrompt;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextDiscovery;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;

/**
 * GF-05 RED: restore SYSTEM-02 bare ancestor AGENTS.md discovery (regression fde4cb989).
 *
 * @group gf-05-prompt-contract
 */
#[Group('gf-05-prompt-contract')]
final class AgentsContextDiscoveryBareRootRegressionTest extends \PHPUnit\Framework\TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('agents-bare-root');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    public function testDiscoversBareCwdAgentsMd(): void
    {
        file_put_contents($this->tmpDir.'/AGENTS.md', 'bare cwd context');

        $results = $this->discovery($this->tmpDir)->discover();

        $this->assertCount(1, $results);
        $this->assertStringEndsWith('/AGENTS.md', $results[0]['path']);
        $this->assertSame('bare cwd context', $results[0]['content']);
    }

    public function testDiscoversBareAncestorAgentsMdNearestFirst(): void
    {
        $child = $this->tmpDir.'/parent/child';
        mkdir($child, 0777, true);
        file_put_contents($this->tmpDir.'/AGENTS.md', 'grandparent bare');
        file_put_contents($this->tmpDir.'/parent/AGENTS.md', 'parent bare');

        $results = $this->discovery($child)->discover();

        $this->assertCount(2, $results);
        $this->assertSame('parent bare', $results[0]['content']);
        $this->assertSame('grandparent bare', $results[1]['content']);
    }

    public function testHatfieldSubdirectoryTakesPrecedenceOverBareInSameDirectory(): void
    {
        mkdir($this->tmpDir.'/.hatfield', 0777, true);
        file_put_contents($this->tmpDir.'/AGENTS.md', 'bare loses');
        file_put_contents($this->tmpDir.'/.hatfield/AGENTS.md', 'hatfield wins');

        $results = $this->discovery($this->tmpDir)->discover();

        $this->assertCount(1, $results);
        $this->assertStringContainsString('.hatfield/AGENTS.md', $results[0]['path']);
        $this->assertSame('hatfield wins', $results[0]['content']);
    }

    public function testAgentsSubdirectoryUsedWhenHatfieldMissing(): void
    {
        mkdir($this->tmpDir.'/.agents', 0777, true);
        file_put_contents($this->tmpDir.'/.agents/AGENTS.md', 'agents folder context');

        $results = $this->discovery($this->tmpDir)->discover();

        $this->assertCount(1, $results);
        $this->assertStringContainsString('.agents/AGENTS.md', $results[0]['path']);
        $this->assertSame('agents folder context', $results[0]['content']);
    }

    private function discovery(string $cwd): AgentsContextDiscovery
    {
        return new AgentsContextDiscovery(
            pathResolver: new SettingsPathResolver($this->tmpDir, $this->tmpDir.'/home'),
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'test'),
                logging: new LoggingConfig(),
                cwd: $cwd,
            ),
        );
    }
}
