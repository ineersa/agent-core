<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class SessionListCommandTest extends TestCase
{
    private string $root;
    private string $project;

    protected function setUp(): void
    {
        $this->root = TestDirectoryIsolation::createProjectTempDir('session-list');
        $this->project = $this->root.'/project';
        $this->assertTrue(mkdir($this->project));
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->root);
    }

    public function testPageContract(): void
    {
        $db = $this->seedStore($this->project, <<<'SQL'
            (2, '/old/place', '2026-11-20 10:00:00', '2026-11-25 04:00:00', 'RÉNAME 100%_', 'private prompt'),
            (10, '/old/./place/', '2026-11-20 10:00:00', '2026-11-25 04:00:00', '', 'Fallback 100%_'),
            (3, '/old/place/child', '2026-11-20 10:00:00', '2026-11-25 04:00:00', 'Other 100xy', NULL),
            (4, '/old/place', '2026-11-20 10:00:00', '2026-11-25 05:00:00', '', NULL)
            SQL);
        $before = hash_file('sha256', $db);
        $report = $this->report($this->runConsole(['session:list', '--json', '--limit=2']));
        $this->assertSame($this->project, $report['scope']['cwd']);
        $this->assertSame(['4', '10'], array_column($report['sessions'], 'sessionId'));
        $this->assertSame(['Session 4', 'Fallback 100%_'], array_column($report['sessions'], 'displayTitle'));
        $this->assertSame(2, $report['nextOffset']);
        $second = $this->report($this->runConsole(['session:list', '--json', '--limit=2', '--offset=2']));
        $this->assertSame(['3', '2'], array_column($second['sessions'], 'sessionId'));
        $this->assertNull($second['nextOffset']);
        $filtered = $this->report($this->runConsole([
            'session:list', '--json', '--since=2026-11-24T23:00:00-05:00', '--until=2026-11-25T04:00:00.0001Z',
            '--query=réname 100%_', '--limit=1',
        ]));
        $this->assertSame([[
            'sessionId' => '2', 'displayTitle' => 'RÉNAME 100%_', 'cwd' => '/old/place',
            'createdAt' => '2026-11-20T10:00:00Z', 'updatedAt' => '2026-11-25T04:00:00Z',
        ]], $filtered['sessions']);
        $this->assertNull($filtered['nextOffset']);
        $literal = $this->report($this->runConsole(['session:list', '--json', '--query=%_']));
        $this->assertSame(['10', '2'], array_column($literal['sessions'], 'sessionId'));
        foreach (['--offset=100', '--query=private prompt', '--until=2026-11-25T04:00:00Z'] as $option) {
            $empty = $this->report($this->runConsole(['session:list', '--json', $option]));
            $this->assertSame([], $empty['sessions']);
            $this->assertNull($empty['nextOffset']);
        }
        $this->assertSame($before, hash_file('sha256', $db));
        $this->assertDirectoryDoesNotExist($this->project.'/.hatfield/sessions');
        $this->assertDirectoryDoesNotExist($this->project.'/.hatfield/extensions');
    }

    public function testSeparateScopeAndDoctrinePathOverride(): void
    {
        $other = $this->root.'/worktree';
        foreach ([['init', '--quiet'], ['commit', '--allow-empty', '-m', 'fixture'], ['worktree', 'add', '--detach', $other]] as $args) {
            (new Process([
                'git', '-c', 'user.email=fixture@example.invalid', '-c', 'user.name=Fixture', '-c', 'core.hooksPath=/dev/null', ...$args,
            ], cwd: $this->project, env: ['HOME' => $this->root.'/home', 'GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'], timeout: 3))->mustRun();
        }
        $db = $this->seedStore($other, "(2, '/past/worktree', '2026-11-25 04:00:00', '2026-11-25 04:00:00', 'External store', NULL)");
        $this->seedStore($this->project, "(2, '/past/worktree', '2026-11-25 04:00:00', '2026-11-25 04:00:00', 'Project store', NULL)");
        $settings = $other.'/.hatfield/settings.yaml';
        $this->assertSame($this->root.'/worktree/.hatfield', realpath(\dirname($settings)));
        $this->assertFileDoesNotExist($settings);
        $this->assertFalse(is_link($settings));
        $config = "sessions:\n  path: elsewhere/sessions\n";
        file_put_contents($settings, $config);
        $project = $this->report($this->runConsole(['session:list', '--json', '--query=2']));
        $external = $this->report($this->runConsole(['session:list', '--json', '--cwd', '../worktree'], $this->project));
        $this->assertSame($this->project, $project['scope']['cwd']);
        $this->assertSame($other, $external['scope']['cwd']);
        $this->assertSame(['Project store'], array_column($project['sessions'], 'displayTitle'));
        $this->assertSame(['External store'], array_column($external['sessions'], 'displayTitle'));
        $this->assertSame(['2'], array_column($project['sessions'], 'sessionId'));
        $this->assertSame(['2'], array_column($external['sessions'], 'sessionId'));

        $override = $this->root.'/catalog with spaces.sqlite';
        $this->assertTrue(rename($db, $override));
        $external = $this->report($this->runConsole(['session:list', '--json', '--cwd='.$other], $this->project, [
            'APP_ENV' => 'test', 'HATFIELD_CONTROLLER_TEST_DATABASE_PATH' => $override,
        ]));
        $this->assertSame($other, $external['scope']['cwd']);
        $this->assertSame(['/past/worktree'], array_column($external['sessions'], 'cwd'));
        $this->assertSame(['External store'], array_column($external['sessions'], 'displayTitle'));
        $this->assertFileDoesNotExist($db);
        $this->assertDirectoryDoesNotExist($other.'/elsewhere');
        $this->assertSame($config, file_get_contents($settings));
    }

    #[TestWith(['--cwd=does-not-exist'])]
    #[TestWith(['--cwd='])]
    #[TestWith(['--since=2026-11-25T04:00:00'])]
    #[TestWith(['--since=2026-02-31T04:00:00Z'])]
    #[TestWith(['--since='])]
    #[TestWith(['--since=2026-11-25T04:00:00Z', '--until=2026-11-24T23:00:00-05:00'])]
    #[TestWith(['--since=2026-11-26T04:00:00Z', '--until=2026-11-25T04:00:00Z'])]
    #[TestWith(['--limit=0'])]
    #[TestWith(['--limit=201'])]
    #[TestWith(['--limit=2.0'])]
    #[TestWith(['--offset=-1'])]
    #[TestWith(['--offset=999999999999999999999999'])]
    #[TestWith(['--offset='])]
    public function testInvalidInputHasNoJson(string ...$options): void
    {
        $this->assertProcessFailed($this->runConsole(['session:list', '--json', ...$options]));
    }

    private function seedStore(string $project, string $tuples): string
    {
        $dir = $project.'/.hatfield';
        $this->assertTrue(mkdir($dir));
        $migrate = $this->runConsole(['doctrine:migrations:migrate', '--no-interaction'], $project);
        $this->assertSame(0, $migrate->getExitCode(), $migrate->getErrorOutput().$migrate->getOutput());
        $seed = $this->runConsole(['dbal:run-sql', 'INSERT INTO hatfield_session (id, cwd, created_at, updated_at, name, prompt) VALUES '.$tuples], $project);
        $this->assertSame(0, $seed->getExitCode(), $seed->getErrorOutput().$seed->getOutput());

        return $dir.'/state.sqlite';
    }

    /**
     * @param list<string>          $options
     * @param array<string, string> $env
     */
    private function runConsole(array $options, ?string $cwd = null, array $env = []): Process
    {
        $process = new Process([
            ...AgentTestExecutable::sourceConsoleCommand(), ...$options,
        ], cwd: $cwd ?? $this->project, env: $env + [
            'APP_ENV' => 'prod',
            'APP_DEBUG' => '0',
            'HOME' => $this->root.'/home',
            'HATFIELD_CACHE_DIR' => $this->root.'/fixture-cache',
            'XDG_CACHE_HOME' => $this->root.'/cache',
        ], timeout: 6);
        $process->run();

        return $process;
    }

    /** @return array{version: int, scope: array{cwd: string}, sessions: list<array<string, string>>, nextOffset: ?int} */
    private function report(Process $process): array
    {
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $this->assertSame('', $process->getErrorOutput());

        return json_decode($process->getOutput(), true, flags: \JSON_THROW_ON_ERROR);
    }

    private function assertProcessFailed(Process $process): void
    {
        $this->assertNotSame(0, $process->getExitCode(), $process->getOutput());
        $this->assertSame('', $process->getOutput());
        $this->assertNotSame('', $process->getErrorOutput());
    }
}
