<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\SessionFileMcpToolCatalogStore;
use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\ControllerReplayE2eTestCase;
use Ineersa\CodingAgent\Tests\Runtime\Controller\Support\OptionalConsumerProbeExtension;
use Ineersa\CodingAgent\Tests\Runtime\Controller\Support\OptionalJobProbeExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Yaml\Yaml;

/**
 * Real controller startup and late MCP activation, synchronized by worker events.
 * Each case needs process-local extension registration and MCP STDIO ownership;
 * a mock supervisor cannot establish those contracts. Timeouts are safety caps.
 */
#[Group('controller-replay')]
final class HeadlessControllerTest extends ControllerReplayE2eTestCase
{
    #[DataProvider('absentRunIds')]
    public function testMissingOrEmptyRunIdDoesNotActivateMcp(?string $runId): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
        $identity = null === $runId ? [] : ['runId' => $runId];

        $this->writeCommand(['v' => 1, 'id' => 'resume-empty', 'type' => 'resume', 'payload' => []] + $identity);
        $error = $this->waitForEvent('protocol.error', 8.0);
        $this->assertSame('resume requires runId', $error['payload']['error'] ?? null);
        $this->assertLaunchCount('mcp', 0);

        // start_run accepts the same absent identity and allocates a session.
        $this->writeCommand(['v' => 1, 'id' => 'start-empty', 'type' => 'start_run', 'payload' => ['prompt' => 'Reply with ready.']] + $identity);
        $events = $this->collectEventsUntil('run.completed', 8.0);
        $byType = $this->indexByType($events);
        $this->assertStartRunAcked($events, 'start-empty');
        $this->assertArrayHasKey('run.completed', $byType, $this->collectDiagnostics($events));
        $this->assertMatchesRegularExpression('/^\d+$/', $byType['run.completed'][0]['runId']);
        $this->assertLaunchCount('mcp', 0);
    }

    /** @return iterable<string, array{?string}> */
    public static function absentRunIds(): iterable
    {
        yield 'missing' => [null];
        yield 'empty' => [''];
    }

    public function testAbsentRolesStayOmittedAndLateRefreshStartsOnePersistentMcpOwner(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
        $this->assertLaunchCount('mcp', 0);
        $this->assertLaunchCount('extension_agent', 0);

        $this->installMcpServer();
        $this->refreshMcp('first');
        $catalog = (new SessionFileMcpToolCatalogStore($this->tempDir))->read($this->sessionId);
        $this->assertNotNull($catalog);
        $this->assertNotEmpty($catalog->servers['echo']->tools);
        $this->assertLaunchCount('mcp', 1);

        // The second refresh reuses the owner, including its existing supervision.
        $this->refreshMcp('second');
        $this->assertLaunchCount('mcp', 1);
        $catalog = (new SessionFileMcpToolCatalogStore($this->tempDir))->read($this->sessionId);
        $this->assertSame(2, $catalog?->generation);
    }

    #[DataProvider('startupConfigurations')]
    public function testStartupUsesCapabilitiesAndPreservesInvalidConfigurationDegradation(string $configuration, bool $expected): void
    {
        if ('enabled' === $configuration) {
            $this->installMcpServer();
        } elseif ('disabled' === $configuration) {
            file_put_contents($this->tempDir.'/.hatfield/mcp.json', json_encode(['mcpServers' => ['echo' => ['command' => \PHP_BINARY, 'enabled' => false]]], \JSON_THROW_ON_ERROR));
        } elseif ('invalid' === $configuration) {
            file_put_contents($this->tempDir.'/.hatfield/mcp.json', '{invalid');
        } else {
            (new SessionFileMcpToolCatalogStore($this->tempDir))->write($this->sessionId, new McpToolCatalogDTO(runId: $this->sessionId));
        }

        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());
        $this->assertLaunchCount('mcp', $expected ? 1 : 0);
        $this->assertLaunchCount('extension_agent', 0);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function startupConfigurations(): iterable
    {
        yield 'enabled server' => ['enabled', true];
        yield 'disabled server' => ['disabled', false];
        yield 'invalid config remains optional' => ['invalid', true];
        yield 'previous catalog requires invalidation owner' => ['catalog', true];
    }

    public function testRegisteredExtensionJobRunsInDedicatedConsumer(): void
    {
        $path = $this->tempDir.'/.hatfield/settings.yaml';
        $settings = Yaml::parseFile($path);
        $settings['extensions']['enabled'][] = OptionalJobProbeExtension::class;
        file_put_contents($path, Yaml::dump($settings, 12));

        $this->spawnController();
        $this->waitForEvent('test.extension.completed', 8.0);
        $this->assertLaunchCount('extension_agent', 1);
        $this->assertLaunchCount('mcp', 0);
    }

    protected function extraSettingsYaml(): string
    {
        return Yaml::dump(['logging' => ['level' => 'info'], 'extensions' => ['enabled' => [OptionalConsumerProbeExtension::class]]], 6);
    }

    protected function tempDirPrefix(): string
    {
        return 'optional-consumers';
    }

    protected function replayFixtures(): array
    {
        return [[
            'fixture_source' => 'synthetic',
            'synthetic_reason' => 'Complete the server-allocated start_run identity regression without provider I/O.',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'stop_reason' => 'stop',
            'deltas' => [['type' => 'text', 'content' => 'ready']],
        ]];
    }

    private function refreshMcp(string $id): void
    {
        $this->writeCommand(['v' => 1, 'id' => $id, 'type' => 'mcp_refresh', 'runId' => $this->sessionId, 'payload' => []]);
        $this->waitForEvent('test.mcp.refreshed', 8.0);
    }

    private function installMcpServer(): void
    {
        file_put_contents($this->tempDir.'/.hatfield/mcp.json', json_encode(['mcpServers' => ['echo' => [
            'command' => \PHP_BINARY,
            'args' => [$this->projectDir.'/tests/CodingAgent/Mcp/Fixtures/stdio-echo-server.php'],
        ]]], \JSON_THROW_ON_ERROR));
    }

    private function assertLaunchCount(string $transport, int $expected): void
    {
        $log = (string) file_get_contents($this->tempDir.'/.hatfield/logs/agent-'.date('Y-m-d').'.log');
        $records = array_filter(explode("\n", $log), static fn (string $line): bool => str_contains($line, '"message":"Launched messenger consumer"') && str_contains($line, '"transport":"'.$transport.'"'));
        $this->assertCount($expected, $records, $log);
    }
}
